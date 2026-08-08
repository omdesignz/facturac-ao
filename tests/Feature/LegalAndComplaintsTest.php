<?php

use App\ComplaintStatus;
use App\LegalDocumentType;
use App\Models\Complaint;
use App\Models\LegalAcceptance;
use App\Models\LegalDocument;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Notifications\ComplaintReceived;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

function publishLegal(LegalDocumentType $type, int $version = 1): LegalDocument
{
    return LegalDocument::factory()
        ->ofType($type)
        ->create(['version' => $version]);
}

// ---------------------------------------------------------------- legal pages

test('the legal pages are readable without an account', function (string $slug, LegalDocumentType $type) {
    publishLegal($type);

    $this->get("/{$slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Legal/Show')
            ->where('document.type', $type->value)
            ->where('document.version', 1)
        );
})->with([
    ['privacidade', LegalDocumentType::Privacy],
    ['termos', LegalDocumentType::Terms],
    ['cookies', LegalDocumentType::Cookies],
]);

test('a document nobody has published is not served', function () {
    $this->get('/termos')->assertNotFound();
});

test('a version dated in the future is written but not yet in force', function () {
    LegalDocument::factory()->create([
        'version' => 1,
        'effective_at' => now()->addWeek(),
        'published_at' => now(),
    ]);

    $this->get('/termos')->assertNotFound();
});

test('the newest published version is the one served', function () {
    publishLegal(LegalDocumentType::Terms, 1);
    publishLegal(LegalDocumentType::Terms, 2);

    $this->get('/termos')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('document.version', 2));
});

test('an unknown slug is not a legal page', function () {
    $this->get('/politica-inventada')->assertNotFound();
});

test('markdown is rendered server-side with raw html escaped', function () {
    LegalDocument::factory()->create([
        'body' => "## Título\n\n<script>alert(1)</script>",
    ]);

    $this->get('/termos')
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $html = $page->toArray()['props']['document']['body_html'];

            expect($html)->toContain('<h2>Título</h2>')
                ->and($html)->not->toContain('<script>');
        });
});

/** The consent cookie a response set, ready to send on the next request. */
function consentCookie(TestResponse $response): string
{
    return (string) $response->getCookie('cookie_consent', false)?->getValue();
}

// ------------------------------------------------------------ cookie consent

test('a first-time visitor is asked about cookies', function () {
    publishLegal(LegalDocumentType::Terms);
    publishLegal(LegalDocumentType::Cookies);

    $this->get('/termos')
        ->assertInertia(fn (Assert $page) => $page
            ->where('legal.cookie_consent_required', true)
        );
});

test('refusing is recorded just like accepting', function () {
    $policy = publishLegal(LegalDocumentType::Cookies);

    $this->post(route('cookie-consent.store'), [
        'preferences' => false,
        'analytics' => false,
    ])->assertRedirect();

    $acceptance = LegalAcceptance::query()->sole();

    expect($acceptance->type)->toBe(LegalDocumentType::Cookies)
        ->and($acceptance->version)->toBe($policy->version)
        // Compared key by key: MySQL's JSON type normalises object key order,
        // and the order was never part of what is being asserted.
        ->and($acceptance->choices)->toMatchArray([
            'essential' => true,
            'preferences' => false,
            'analytics' => false,
        ]);
});

test('an answered visitor is not asked again', function () {
    publishLegal(LegalDocumentType::Terms);
    publishLegal(LegalDocumentType::Cookies);

    $response = $this->post(route('cookie-consent.store'), [
        'preferences' => true,
        'analytics' => true,
    ]);

    // Carried forward by hand: the test client does not keep cookies between
    // requests the way a browser does.
    $this->withUnencryptedCookie('cookie_consent', consentCookie($response))
        ->get('/termos')
        ->assertInertia(fn (Assert $page) => $page
            ->where('legal.cookie_consent_required', false)
        );
});

test('a newer cookie policy asks again', function () {
    publishLegal(LegalDocumentType::Terms);
    publishLegal(LegalDocumentType::Cookies, 1);

    $response = $this->post(route('cookie-consent.store'), [
        'preferences' => true,
        'analytics' => true,
    ]);

    publishLegal(LegalDocumentType::Cookies, 2);

    // Consent against the old text is not consent to the new one.
    $this->withUnencryptedCookie('cookie_consent', consentCookie($response))
        ->get('/termos')
        ->assertInertia(fn (Assert $page) => $page
            ->where('legal.cookie_consent_required', true)
        );
});

test('a malformed consent cookie asks again rather than assuming yes', function () {
    publishLegal(LegalDocumentType::Terms);
    publishLegal(LegalDocumentType::Cookies);

    $this->withUnencryptedCookie('cookie_consent', 'not-json')
        ->get('/termos')
        ->assertInertia(fn (Assert $page) => $page
            ->where('legal.cookie_consent_required', true)
        );
});

// ------------------------------------------------------------ terms acceptance

test('a user who never accepted the terms is asked to', function () {
    publishLegal(LegalDocumentType::Terms);
    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('legal.terms_reacceptance_required', true)
        );
});

test('accepting is recorded against the version in force', function () {
    $terms = publishLegal(LegalDocumentType::Terms);
    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user)
        ->post(route('legal.terms.accept'))
        ->assertRedirect();

    $acceptance = LegalAcceptance::query()->sole();

    expect($acceptance->user_id)->toBe($user->id)
        ->and($acceptance->version)->toBe($terms->version);

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('legal.terms_reacceptance_required', false)
        );
});

test('publishing new terms asks the user again', function () {
    publishLegal(LegalDocumentType::Terms, 1);
    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user)->post(route('legal.terms.accept'));

    publishLegal(LegalDocumentType::Terms, 2);

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('legal.terms_reacceptance_required', true)
        );
});

test('accepting twice does not duplicate the record', function () {
    publishLegal(LegalDocumentType::Terms);
    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user)->post(route('legal.terms.accept'));
    $this->post(route('legal.terms.accept'));

    expect(LegalAcceptance::query()->count())->toBe(1);
});

// ------------------------------------------------------------------ complaints

test('a complaint is entered in the book with a citable reference', function () {
    Notification::fake();

    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user)
        ->post(route('help.complaints.store'), [
            'category' => 'invoicing',
            'subject' => 'A factura não segue para a AGT',
            'body' => 'Emiti a FT 2026/14 e continua por comunicar há duas horas.',
            'contact_name' => 'Ana Manuel',
            'contact_email' => 'ana@empresa.ao',
            'contact_phone' => '+244 923 000 111',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $complaint = Complaint::query()->sole();

    expect($complaint->reference)->toStartWith('REC-'.now()->year)
        ->and($complaint->status)->toBe(ComplaintStatus::Open)
        ->and($complaint->user_id)->toBe($user->id)
        ->and($complaint->response_due_at->isFuture())->toBeTrue();
});

test('the complainant is emailed the reference', function () {
    Notification::fake();

    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user)->post(route('help.complaints.store'), [
        'category' => 'billing',
        'subject' => 'Cobrança duplicada',
        'body' => 'Fui cobrado duas vezes pelo mesmo período de assinatura.',
        'contact_name' => 'Ana Manuel',
        'contact_email' => 'ana@empresa.ao',
        'contact_phone' => null,
    ]);

    Notification::assertSentTo(
        Complaint::query()->sole(),
        ComplaintReceived::class,
        fn (ComplaintReceived $notification): bool => $notification->reference !== '',
    );
});

test('references do not repeat', function () {
    Notification::fake();

    $user = User::factory()->withWorkspace()->create();

    foreach (range(1, 3) as $index) {
        $this->actingAs($user)->post(route('help.complaints.store'), [
            'category' => 'support',
            'subject' => "Assunto {$index}",
            'body' => 'Descrição suficientemente longa para passar a validação.',
            'contact_name' => 'Ana Manuel',
            'contact_email' => 'ana@empresa.ao',
            'contact_phone' => null,
        ]);
    }

    expect(Complaint::query()->pluck('reference')->unique())->toHaveCount(3);
});

test('a complaint too vague to investigate is refused', function () {
    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user)
        ->post(route('help.complaints.store'), [
            'category' => 'support',
            'subject' => 'Erro',
            'body' => 'Não funciona',
            'contact_name' => 'Ana',
            'contact_email' => 'ana@empresa.ao',
            'contact_phone' => null,
        ])
        ->assertSessionHasErrors('body');
});

test('the help page shows the configured channels and the consumer authority', function () {
    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user)
        ->get(route('help.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Help/Index')
            ->where('channels.support_email', PlatformSetting::get('support_email'))
            ->where('consumerAuthority.name', config('platform.consumer_authority.name'))
        );
});

test('a customer sees their own complaints and nobody else', function () {
    $mine = User::factory()->withWorkspace()->create();
    $theirs = User::factory()->withWorkspace()->create();

    Complaint::factory()->create(['user_id' => $mine->id, 'subject' => 'A minha']);
    Complaint::factory()->create(['user_id' => $theirs->id, 'subject' => 'A deles']);

    $this->actingAs($mine)
        ->get(route('help.index'))
        ->assertInertia(function (Assert $page) {
            $subjects = collect($page->toArray()['props']['complaints'])->pluck('subject');

            expect($subjects)->toContain('A minha')
                ->and($subjects)->not->toContain('A deles');
        });
});

test('a complaint past its deadline is flagged as overdue', function () {
    $user = User::factory()->withWorkspace()->create();

    Complaint::factory()->create([
        'user_id' => $user->id,
        'response_due_at' => now()->subDay(),
    ]);

    expect(Complaint::query()->sole()->isOverdue())->toBeTrue();
});

test('a resolved complaint is never overdue', function () {
    Complaint::factory()->resolved()->create(['response_due_at' => now()->subWeek()]);

    expect(Complaint::query()->sole()->isOverdue())->toBeFalse();
});

// ------------------------------------------------------------- staff handling

test('the complaints book is hidden from customers', function () {
    $customer = User::factory()->withWorkspace()->create();

    $this->actingAs($customer)
        ->get(route('support.complaints.index'))
        ->assertNotFound();
});

test('staff can answer and close a complaint', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $complaint = Complaint::factory()->create();

    $this->actingAs($staff)
        ->put(route('support.complaints.update', $complaint), [
            'status' => 'resolved',
            'resolution' => 'Corrigimos o certificado da série e recomunicámos o documento.',
        ])
        ->assertRedirect();

    $complaint->refresh();

    expect($complaint->status)->toBe(ComplaintStatus::Resolved)
        ->and($complaint->resolved_at)->not->toBeNull()
        ->and($complaint->handled_by_user_id)->toBe($staff->id);
});

test('a complaint cannot be closed without an answer', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $complaint = Complaint::factory()->create();

    $this->actingAs($staff)
        ->put(route('support.complaints.update', $complaint), [
            'status' => 'resolved',
            'resolution' => '   ',
        ])
        ->assertSessionHasErrors('resolution');

    expect($complaint->fresh()->status)->toBe(ComplaintStatus::Open);
});

// -------------------------------------------------------------------- settings

test('staff can change the contact details customers are shown', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();

    $this->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('support.settings.update'), [
            ...PlatformSetting::values(),
            'support_phone' => '+244 999 111 222',
        ])
        ->assertRedirect();

    expect(PlatformSetting::get('support_phone'))->toBe('+244 999 111 222');
});

test('a blank override falls back to the default rather than showing nothing', function () {
    PlatformSetting::query()->create(['key' => 'support_phone', 'value' => '   ']);
    PlatformSetting::flush();

    expect(PlatformSetting::get('support_phone'))
        ->toBe(config('platform.settings.support_phone'));
});

test('an unknown key cannot be smuggled into the settings', function () {
    PlatformSetting::put(['not_a_setting' => 'x']);

    expect(PlatformSetting::values())->not->toHaveKey('not_a_setting');
});

test('customers cannot change the platform settings', function () {
    $customer = User::factory()->withWorkspace()->create();

    $this->actingAs($customer)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('support.settings.update'), PlatformSetting::values())
        ->assertNotFound();
});
