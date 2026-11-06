<?php

use App\Actions\SignInMobileDevice;
use App\Fiscal\Documents\DocumentLiveActivities;
use App\FiscalDocumentType;
use App\Models\Customer;
use App\Models\DocumentLiveActivity;
use App\Models\FiscalDocument;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../PosFixtures.php';

function configureLiveActivityApns(): void
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($key, $privatePem);

    config([
        'services.apns.key_id' => 'KEY1234567',
        'services.apns.team_id' => 'TEAM123456',
        'services.apns.private_key' => str_replace("\n", '\n', $privatePem),
        'services.apns.bundle_id' => 'ao.facturac.app',
    ]);
}

/**
 * A company with one invoice issued from the phone, followed on its lock screen.
 *
 * @return array{company: array<string, mixed>, invoice: FiscalDocument}
 */
function trackedInvoice(): array
{
    $company = posCompany();
    $company['owner']->forceFill(['password' => Hash::make('a-very-good-password')])->save();
    posSeries($company['legalEntity'], $company['establishment'], $company['connection'], [
        'series_code' => 'FTAPP',
        'document_type' => FiscalDocumentType::Invoice,
    ]);
    $company['token'] = $company['owner']->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken;
    $customer = Customer::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
    ]);

    $publicId = test()->withToken($company['token'])->postJson(route('mobile.v1.invoices.store'), [
        'client_key' => (string) Str::uuid(),
        'customer_public_id' => $customer->public_id,
        'lines' => [['catalogue_item_public_id' => posItem($company)->public_id, 'quantity' => '1']],
        'expected_total_minor' => 11_400,
        'issue' => true,
        'password' => 'a-very-good-password',
    ])->assertCreated()->json('data.public_id');

    test()->withToken($company['token'])
        ->putJson(route('mobile.v1.documents.live-activity', $publicId), ['token' => 'a1b2c3', 'environment' => 'sandbox'])
        ->assertNoContent();

    return ['company' => $company, 'invoice' => FiscalDocument::query()->where('public_id', $publicId)->firstOrFail()];
}

beforeEach(function () {
    Queue::fake();
});

test('the phone registers the tracker it started for a document, once', function () {
    ['company' => $company, 'invoice' => $invoice] = trackedInvoice();

    $this->withToken($company['token'])
        ->putJson(route('mobile.v1.documents.live-activity', $invoice->public_id), ['token' => 'a1b2c3', 'environment' => 'sandbox'])
        ->assertNoContent();

    $activity = DocumentLiveActivity::query()->sole();

    expect($activity->fiscal_document_id)->toBe($invoice->id)
        ->and($activity->user_id)->toBe($company['owner']->id)
        ->and($activity->ended_at)->toBeNull();
});

test('a token that is not Apple\'s, or a document of another company, is refused', function () {
    ['company' => $company, 'invoice' => $invoice] = trackedInvoice();

    $this->withToken($company['token'])
        ->putJson(route('mobile.v1.documents.live-activity', $invoice->public_id), ['token' => 'not hex!', 'environment' => 'sandbox'])
        ->assertUnprocessable();

    $this->withToken($company['token'])
        ->putJson(route('mobile.v1.documents.live-activity', 'someone-elses'), ['token' => 'ffee', 'environment' => 'sandbox'])
        ->assertNotFound();
});

test('the AGT receiving and then validating moves the tracker on, and the answer ends it', function () {
    configureLiveActivityApns();
    ['invoice' => $invoice] = trackedInvoice();
    Http::fake(['*' => Http::response(status: 200)]);

    recordAuthoritativeAgtAcceptance($invoice);

    $sent = collect(Http::recorded())->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => str_contains($request->url(), 'push.apple.com/3/device/a1b2c3'))
        ->values();

    expect($sent)->toHaveCount(2)
        ->and($sent[0]->header('apns-push-type')[0])->toBe('liveactivity')
        ->and($sent[0]->header('apns-topic')[0])->toBe('ao.facturac.app.push-type.liveactivity')
        ->and($sent[0]['aps']['event'])->toBe('update')
        ->and($sent[0]['aps']['content-state'])->toBe(['stage' => 'received', 'label' => 'Recebida pela AGT'])
        ->and($sent[1]['aps']['event'])->toBe('end')
        ->and($sent[1]['aps']['content-state']['stage'])->toBe('validated')
        ->and($sent[1]['aps'])->toHaveKey('dismissal-date')
        ->and(DocumentLiveActivity::query()->sole()->ended_at)->not->toBeNull();
});

test('a tracker Apple no longer knows is forgotten, and an ended one hears nothing more', function () {
    configureLiveActivityApns();
    ['invoice' => $invoice] = trackedInvoice();
    $activity = DocumentLiveActivity::query()->sole();
    Http::fake(['*' => Http::response(['reason' => 'BadDeviceToken'], 400)]);

    app(DocumentLiveActivities::class)->advance($invoice, DocumentLiveActivities::RECEIVED);

    expect(DocumentLiveActivity::query()->count())->toBe(0);

    DocumentLiveActivity::query()->create([
        ...$activity->only(['user_id', 'personal_access_token_id', 'fiscal_document_id', 'environment']),
        'token' => 'e1d2',
        'ended_at' => now(),
    ]);
    Http::fake(['*' => Http::response(status: 200)]);

    app(DocumentLiveActivities::class)->advance($invoice, DocumentLiveActivities::VALIDATED);

    Http::assertNothingSent();
});

test('without an APNs key nothing is sent, and the phone reads the stage itself', function () {
    ['company' => $company, 'invoice' => $invoice] = trackedInvoice();
    Http::fake();

    app(DocumentLiveActivities::class)->advance($invoice, DocumentLiveActivities::RECEIVED);

    Http::assertNothingSent();

    $this->withToken($company['token'])->getJson(route('mobile.v1.documents.show', $invoice->public_id))
        ->assertJsonPath('data.live_stage', 'signed');

    recordAuthoritativeAgtAcceptance($invoice);

    $this->withToken($company['token'])->getJson(route('mobile.v1.documents.show', $invoice->public_id))
        ->assertJsonPath('data.live_stage', 'validated');
});
