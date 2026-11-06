<?php

use App\Actions\SignInMobileDevice;
use App\Models\Quote;
use App\Models\User;
use App\Notifications\QuoteWasAccepted;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

require_once __DIR__.'/../PosFixtures.php';

function inboxToken(User $user): string
{
    return $user->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken;
}

/**
 * @param  array<string, mixed>  $data
 */
function inboxRow(User $user, ?int $workspaceId, array $data = [], bool $read = false): DatabaseNotification
{
    return DatabaseNotification::query()->forceCreate([
        'id' => (string) Str::uuid(),
        'type' => QuoteWasAccepted::class,
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => [
            'topic' => 'quote_accepted',
            'title' => 'ORC 2026/0001 aceite',
            'body' => 'O cliente aceitou.',
            'url' => 'https://facturac.ao/orcamentos/q1',
            'workspace_id' => $workspaceId,
            ...$data,
        ],
        'read_at' => $read ? now() : null,
    ]);
}

it('shows this company\'s news and the account\'s, but not another company\'s', function (): void {
    $company = posCompany();
    $workspaceId = $company['legalEntity']->workspace_id;
    $here = inboxRow($company['owner'], $workspaceId);
    $account = inboxRow($company['owner'], null, ['title' => 'A equipa de apoio entrou na conta']);
    $elsewhere = inboxRow($company['owner'], $workspaceId + 1000);

    $response = $this->withToken(inboxToken($company['owner']))->getJson(route('mobile.v1.notifications.index'))
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toContain($here->id, $account->id)
        ->not->toContain($elsewhere->id);

    $this->getJson(route('mobile.v1.notifications.unread'))->assertJsonPath('data.unread', 2);
});

it('filters to what has not been read', function (): void {
    $company = posCompany();
    $workspaceId = $company['legalEntity']->workspace_id;
    $unread = inboxRow($company['owner'], $workspaceId);
    inboxRow($company['owner'], $workspaceId, read: true);

    $this->withToken(inboxToken($company['owner']))->getJson(route('mobile.v1.notifications.index', ['only_unread' => 1]))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $unread->id)
        ->assertJsonPath('data.0.read', false)
        ->assertJsonPath('data.0.topic_label', fn (?string $label): bool => filled($label));
});

it('marks one as read, and a repeat is harmless', function (): void {
    $company = posCompany();
    $row = inboxRow($company['owner'], $company['legalEntity']->workspace_id);
    $token = inboxToken($company['owner']);

    foreach ([1, 2] as $attempt) {
        $this->withToken($token)->postJson(route('mobile.v1.notifications.read', $row->id))
            ->assertOk()
            ->assertJsonPath('data.read', true);
    }

    $this->getJson(route('mobile.v1.notifications.unread'))->assertJsonPath('data.unread', 0);
});

it('will not read someone else\'s notification, or one from another company', function (): void {
    $company = posCompany();
    $colleague = User::factory()->create();
    $theirs = inboxRow($colleague, $company['legalEntity']->workspace_id);
    $elsewhere = inboxRow($company['owner'], $company['legalEntity']->workspace_id + 1000);
    $token = inboxToken($company['owner']);

    $this->withToken($token)->postJson(route('mobile.v1.notifications.read', $theirs->id))->assertNotFound();
    $this->withToken($token)->postJson(route('mobile.v1.notifications.read', $elsewhere->id))->assertNotFound();

    expect($theirs->fresh()->read_at)->toBeNull()
        ->and($elsewhere->fresh()->read_at)->toBeNull();
});

it('marks everything in this company as read, and leaves the other company alone', function (): void {
    $company = posCompany();
    $workspaceId = $company['legalEntity']->workspace_id;
    $here = inboxRow($company['owner'], $workspaceId);
    $elsewhere = inboxRow($company['owner'], $workspaceId + 1000);

    $this->withToken(inboxToken($company['owner']))->postJson(route('mobile.v1.notifications.read-all'))
        ->assertOk()
        ->assertJsonPath('data.unread', 0);

    expect($here->fresh()->read_at)->not->toBeNull()
        ->and($elsewhere->fresh()->read_at)->toBeNull();
});

it('keeps what the app can open on the row, and old rows fall back to the web', function (): void {
    $company = posCompany();
    $workspaceId = $company['legalEntity']->workspace_id;
    $quote = Quote::factory()->create([
        'workspace_id' => $workspaceId,
        'legal_entity_id' => $company['legalEntity']->id,
        'establishment_id' => $company['establishment']->id,
        'reference' => 'ORC 2026/0042',
        'gross_total_minor' => 22_800,
    ]);
    $old = inboxRow($company['owner'], $workspaceId);

    $company['owner']->notify(QuoteWasAccepted::fromQuote($quote));

    $rows = collect($this->withToken(inboxToken($company['owner']))->getJson(route('mobile.v1.notifications.index'))->json('data'));
    $new = $rows->firstWhere('id', '!=', $old->id);

    expect($new['target'])->toBe(['type' => 'quote', 'public_id' => $quote->public_id, 'label' => 'ORC 2026/0042'])
        ->and($rows->firstWhere('id', $old->id)['target'])->toBeNull()
        ->and($rows->firstWhere('id', $old->id)['web_url'])->toBe('https://facturac.ao/orcamentos/q1');
});

it('needs a phone token', function (): void {
    $this->getJson(route('mobile.v1.notifications.index'))->assertUnauthorized();
});
