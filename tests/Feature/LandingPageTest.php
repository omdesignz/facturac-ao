<?php

use App\DataImportType;
use App\LegalDocumentType;
use App\Models\LegalDocument;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Province;
use App\SubscriptionInterval;
use Inertia\Testing\AssertableInertia as Assert;

test('a visitor without an account gets the landing page', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Landing'));
});

test('the landing page asks a first-time visitor about cookie choices', function () {
    LegalDocument::factory()->ofType(LegalDocumentType::Cookies)->create();

    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Landing')
            ->where('legal.cookie_consent_required', true)
            ->etc()
        );

    expect((string) file_get_contents(resource_path('js/pages/Landing.vue')))
        ->toContain("import CookieConsent from '@/components/CookieConsent.vue';")
        ->toContain('<CookieConsent />');
});

test('the landing page offers interactive product proof with accessible motion', function () {
    $landing = (string) file_get_contents(resource_path('js/pages/Landing.vue'));
    $wordmark = (string) file_get_contents(resource_path('js/components/BrandWordmark.vue'));

    expect($landing)
        ->toContain("import BrandWordmark from '@/components/BrandWordmark.vue';")
        ->toContain('aria-label="Escolher um momento do negócio"')
        ->toContain('aria-label="Escolher estado do documento"')
        ->toContain('@click="selectLifecycle(index)"')
        ->toContain('(prefers-reduced-motion: reduce)')
        ->and($wordmark)
        ->toContain('role="img"')
        ->toContain('@media (prefers-reduced-motion: reduce)');
});

test('the wordmark is drawn as outlines so it never depends on a font file', function () {
    // Century Gothic is licensed for documents, not for serving to browsers, so
    // the logo has to be artwork rather than text set in the typeface.
    $wordmark = (string) file_get_contents(resource_path('js/components/BrandWordmark.vue'));

    expect($wordmark)
        ->toContain('<path')
        ->toContain('class="wordmark-dot"')
        ->not->toContain('<text')
        ->not->toContain('font-family');
});

test('someone already signed in is sent to their dashboard', function () {
    // They did not come to the root to be sold the thing they already pay for.
    $this->actingAs(User::factory()->withWorkspace('VAP Landing')->create())
        ->get('/')
        ->assertRedirect(route('dashboard'));
});

test('the page counts the provinces rather than stating a number', function () {
    $current = count(array_filter(
        Province::cases(),
        fn (Province $province): bool => $province->isCurrent(),
    ));

    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('provinceCount', $current)
            ->etc()
        );
});

test('no price is shown while none is published', function () {
    /*
     * A price is a commercial promise. With nothing in the plans table the page
     * has nothing to promise, and inventing a figure to fill the section would
     * be worse than leaving it empty.
     */
    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('plans', [])->etc());
});

test('a published plan reaches the page as it was recorded', function () {
    SubscriptionPlan::query()->create([
        'code' => 'balcao',
        'name' => 'Balcão',
        'summary' => 'Para quem factura sozinho.',
        'amount_minor' => 750_000,
        'currency_code' => 'AOA',
        'interval' => SubscriptionInterval::Monthly,
        'trial_days' => 14,
        'features' => ['Facturas ilimitadas', 'SAF-T (AO)'],
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('plans.0.name', 'Balcão')
            ->where('plans.0.amount', '7 500')
            ->where('plans.0.currency', 'AOA')
            ->where('plans.0.features.0', 'Facturas ilimitadas')
            ->etc()
        );
});

test('a withdrawn plan is not advertised', function () {
    SubscriptionPlan::query()->create([
        'code' => 'antigo',
        'name' => 'Plano antigo',
        'amount_minor' => 100_000,
        'currency_code' => 'AOA',
        'interval' => SubscriptionInterval::Monthly,
        'trial_days' => 0,
        'is_active' => false,
        'sort_order' => 9,
    ]);

    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('plans', [])->etc());
});

test('the legal pages the footer points at open without an account', function () {
    // A footer link that 404s is worse than no footer link, and someone still
    // deciding whether to sign up has to be able to read the terms first.
    foreach (LegalDocumentType::cases() as $type) {
        // Publishing is deliberately not mass-assignable, so it is a separate
        // and visible act rather than a field someone can post.
        LegalDocument::query()->create([
            'type' => $type,
            'version' => 1,
            'title' => $type->value,
            'body' => 'Conteúdo de exemplo.',
            'effective_at' => now()->subDay(),
        ])->forceFill(['published_at' => now()->subDay()])->save();
    }

    foreach (LegalDocumentType::cases() as $type) {
        $this->get('/'.$type->slug())->assertOk();
    }
});

test('the migration copy promises only what the import accepts', function () {
    $page = (string) file_get_contents(resource_path('js/pages/Landing.vue'));

    // Customers and catalogue items are what an import can bring in. If that
    // list grows, the page can say so; until then it must not promise debts.
    expect(array_map(fn (DataImportType $type): string => $type->value, DataImportType::cases()))
        ->toBe(['customers', 'catalogue_items'])
        ->and($page)->toContain('Clientes e artigos mudam')
        ->toContain('Clientes e artigos ficam prontos para facturar')
        ->not->toContain('artigos e dívidas');
});

test('the counter section sells the till with four promises and nothing it cannot keep', function () {
    $page = (string) file_get_contents(resource_path('js/pages/Landing.vue'));

    expect($page)
        ->toContain('id="balcao"')
        ->toContain('<!-- ------------------------------------------------------- counter -->')
        ->toContain('Ao balcão, é tocar e cobrar')
        ->toContain('Toque no artigo ou leia o código de barras.')
        ->toContain('Numerário com troco calculado, Multicaixa ou transferência.')
        ->toContain('O stock desce sozinho a cada venda.')
        ->toContain('Abre e fecha a caixa com a contagem do dinheiro.')
        ->toContain('O talão sai em impressoras térmicas de 80 mm.')
        ->toContain('Cobrar 139 080 Kz')
        // The mock is decorative: nothing in it is read out or focusable.
        ->toContain('aria-hidden="true"')
        // The till is reachable from the footer, but the header keeps four links.
        ->toContain("{ href: '#balcao', label: 'Ponto de venda' }")
        ->toContain('v-for="section in footerSections"');

    // Nothing the till cannot do today: no selling without a connection, and no
    // card terminal wired into the sale. Multicaixa is recorded as a payment.
    foreach (['offline', 'sem internet', 'TPA integrado'] as $claim) {
        expect(mb_strtolower($page))->not->toContain(mb_strtolower($claim));
    }
});

test('the counter shows up as a situation, a feature and a section that agree', function () {
    $page = (string) file_get_contents(resource_path('js/pages/Landing.vue'));

    expect($page)
        // Second in the list of situations, after the invoice at the customer's side.
        ->toContain("tag: 'PDV'")
        ->toContain('Tem fila ao balcão e cada cliente quer o talão.')
        ->toContain('O talão imprime numa impressora térmica de 80 mm.')
        // Listed first under "A casa em ordem".
        ->toContain("group: 'A casa em ordem',\n        items: [\n            'Ponto de venda com abertura e fecho de caixa',")
        // The header links stay at four.
        ->toContain("{ href: '#perguntas', label: 'Perguntas' },\n];");

    expect(strpos($page, "tag: 'PDV'"))
        ->toBeGreaterThan(strpos($page, "tag: 'FR'"))
        ->toBeLessThan(strpos($page, "tag: 'Conta'"))
        // The counter sits between the phone and the migration banner.
        ->and(strpos($page, 'id="pequenos"'))->toBeLessThan(strpos($page, 'id="balcao"'))
        ->and(strpos($page, 'id="balcao"'))->toBeLessThan(strpos($page, 'id="mudar"'));

    // Every total in the selling screen is the hero document's own.
    expect($page)
        ->toContain('122 000,00')
        ->toContain('17 080,00')
        ->toContain('139 080,00')
        ->toContain("{ name: 'Prateleira metálica', quantity: '2 × 32 000', total: '64 000' }");
});
