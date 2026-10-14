<?php

use App\FiscalDocumentType;
use App\Models\User;
use App\TransportDocumentType;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

test('the guide and PDF require an authenticated account', function () {
    $this->get(route('help.guide.index'))->assertRedirect(route('login'));
    $this->get(route('help.guide.pdf'))->assertRedirect(route('login'));
});

test('the guide is available before workspace setup and contains complete linked workflows', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('help.guide.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Help/Guide')
            ->where('selectedChapter', null)
            ->where('progressKey', 'facturac.guide.2026.10.'.$user->id)
            ->has('guide.chapters', 16)
            ->where('guide.chapters.0.url', route('onboarding'))
        );

    $chapters = config('user-guide.chapters');
    $ids = array_column($chapters, 'id');
    expect(array_unique($ids))->toHaveCount(count($chapters));
    foreach ($chapters as $chapter) {
        expect($chapter['title'])->not->toBeEmpty()
            ->and($chapter['summary'])->not->toBeEmpty()
            ->and($chapter['steps'])->toHaveCount(3)
            ->and($chapter['checklist'])->toHaveCount(3)
            ->and($chapter['tip'])->not->toBeEmpty()
            ->and($chapter['answer'])->not->toBeEmpty()
            ->and(Route::getRoutes()->getByName($chapter['route'])?->methods())->toContain('GET');
        foreach ($chapter['related'] as $related) {
            expect($ids)->toContain($related);
        }
    }
});

test('the guide opens on its home, shelving every chapter under a described topic', function () {
    $guide = config('user-guide');
    $ids = array_column($guide['chapters'], 'id');

    foreach ($guide['chapters'] as $chapter) {
        expect($guide['categories'])->toHaveKey($chapter['category']);
    }
    foreach ($guide['categories'] as $description) {
        expect($description)->not->toBeEmpty();
    }
    foreach ($guide['popular'] as $task) {
        expect($ids)->toContain($task['chapter'])
            ->and($task['label'])->not->toBeEmpty();
    }

    $this->actingAs(User::factory()->create())
        ->get(route('help.guide.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedChapter', null)
            ->where('guide.categories', $guide['categories'])
            ->where('guide.popular', $guide['popular'])
        );
});

test('chapters can be deep linked and invalid chapters are rejected', function () {
    $this->actingAs(User::factory()->create());
    $this->get(route('help.guide.index', ['chapter' => 'pos']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('selectedChapter', 'pos'));
    $this->get(route('help.guide.index', ['chapter' => 'missing']))->assertNotFound();
    $this->get(route('help.guide.index', ['chapter' => ['pos']]))->assertNotFound();
});

test('solo entrepreneur guidance is linked and preserves the boundary between tests and real issuance', function () {
    $chapters = collect(config('user-guide.chapters'));
    $chapter = $chapters->firstWhere('id', 'empreendedor-agt');

    $this->actingAs(User::factory()->create())
        ->get(route('help.guide.index', ['chapter' => $chapter['id']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedChapter', 'empreendedor-agt')
            ->where('guide.chapters.2.id', 'empreendedor-agt')
            ->where('guide.chapters.2.url', route('onboarding'))
            ->where('guide.chapters.2.tip', $chapter['tip'])
        );

    expect($chapters->firstWhere('id', 'comecar')['related'])->toContain($chapter['id'])
        ->and($chapters->firstWhere('id', 'empresa')['related'])->toContain($chapter['id'])
        ->and($chapter['summary'])->toContain('certificação do software cabe à VAP', 'adesão fiscal é da sua empresa', 'Não precisa de repetir a homologação')
        ->and($chapter['steps'][0]['body'])->toContain('Escolha e confirme explicitamente o regime fiscal', 'dois factores (MFA)')
        ->and($chapter['steps'][1]['body'])->toContain('credenciais API e a chave do produtor', 'empresa, NIF e ambiente de testes', 'chave previamente preparada', 'palavra-passe Facturac', 'Portal do Contribuinte fica consigo')
        ->and($chapter['steps'][2]['body'])->toContain('a consulta não emite facturas', 'série FT autorizada e utilizável', 'primeira factura de teste', 'verificação controlada pela VAP')
        ->and($chapter['tip'])->toContain('verificado localmente', 'disponibilização da versão', 'activação AGT em produção', 'custódia reais das chaves', 'séries de produção')
        ->and($chapter['answer'])->toContain('Ajuda e reclamações', 'Nunca envie', 'email ou chat');

    $html = view('help.guide-pdf', ['guide' => config('user-guide'), 'logo' => public_path('brand/facturac-ao.svg')])->render();
    expect($html)->toContain('href="#empreendedor-agt"', 'name="empreendedor-agt"', e($chapter['title']), e($chapter['summary']), e($chapter['tip']), e($chapter['answer']));
    foreach ($chapter['steps'] as $step) {
        expect($html)->toContain(e($step['title']), e($step['body']));
    }
});

test('reading progress is separated between user accounts', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    foreach ([$first, $second] as $user) {
        $this->actingAs($user)->get(route('help.guide.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('progressKey', 'facturac.guide.2026.10.'.$user->id));
    }
});

test('the complete guide downloads as a branded PDF with cover contents and every chapter', function () {
    $response = $this->actingAs(User::factory()->create())
        ->get(route('help.guide.pdf'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertDownload('facturac-guia-de-utilizacao.pdf');
    $pdf = $response->getContent();
    expect($pdf)->toStartWith('%PDF-')->and(strlen($pdf))->toBeGreaterThan(20000);
    expect(view('help.guide-pdf', ['guide' => config('user-guide'), 'logo' => public_path('brand/facturac-ao.svg')])->render())
        ->toContain('class="cover"', 'Comece por aqui.', 'name="guide-footer"');
    if ($path = getenv('GUIDE_PDF_PREVIEW')) {
        file_put_contents($path, $pdf);
    }
});

test('the guide HTML is rendered in the authenticated application shell', function () {
    $user = User::factory()->withWorkspace('Guide preview')->create();
    $response = $this->actingAs($user)->get(route('help.guide.index'))->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('Help/Guide'));
    if ($path = getenv('GUIDE_HTML_PREVIEW')) {
        file_put_contents($path, $response->getContent());
    }
});

test('document scenarios cover every issuable type and distinguish unavailable options', function () {
    $chapters = collect(config('user-guide.chapters'));
    $scenarios = $chapters->flatMap(fn (array $chapter): array => $chapter['scenarios'] ?? []);
    foreach (FiscalDocumentType::issuable() as $type) {
        expect($scenarios->pluck('code'))->toContain($type->value);
    }
    foreach (TransportDocumentType::cases() as $type) {
        expect($scenarios->pluck('code'))->toContain($type->value);
    }
    expect($scenarios->firstWhere('code', 'PF')['action'])->toContain('ainda não dispõe')
        ->and($scenarios->firstWhere('code', 'GC')['action'])->toContain('bloqueada')
        ->and($scenarios->firstWhere('code', 'GF')['when'])->toContain('instituição financeira');
    $html = view('help.guide-pdf', ['guide' => config('user-guide'), 'logo' => public_path('brand/facturac-ao.svg')])->render();
    foreach ($scenarios as $scenario) {
        expect($html)->toContain(e($scenario['example']), e($scenario['attention']));
    }
    expect($html)->toContain('brand/vap-developer.svg', 'DESENVOLVIDO POR VAP', 'brand/guide-cover-art.svg');
});

test('guide screenshots have real application screens with demonstration data', function () {
    require_once __DIR__.'/../PosFixtures.php';
    $company = posCompany();
    $company['owner']->update(['name' => 'Joana Demo', 'email' => 'joana@example.test']);
    $company['legalEntity']->update(['legal_name' => 'Empresa de demonstração', 'trade_name' => 'Loja Demo']);
    $company['owner']->currentWorkspace()->firstOrFail()->update(['name' => 'Demonstração']);
    posOpenSession($company);
    posSeries($company['legalEntity'], $company['establishment'], $company['connection'], [
        'document_type' => FiscalDocumentType::SalesTicket,
        'series_code' => 'TVDEMO',
    ]);
    posItem($company, ['code' => 'DEMO-01', 'name' => 'Bilhete de entrada', 'type' => 'service']);
    $this->actingAs($company['owner']);
    foreach (['pos' => ['pos.show', 'Pos/Index'], 'transport' => ['transport-documents.create', 'TransportDocuments/Form']] as $name => [$route, $component]) {
        $response = $this->get(route($route))->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->component($component));
        if ($directory = getenv('GUIDE_SCREEN_PREVIEWS')) {
            file_put_contents($directory.'/'.$name.'.html', $response->getContent());
        }
    }
    foreach (config('user-guide.chapters') as $chapter) {
        foreach ($chapter['screenshots'] ?? [] as $screenshot) {
            expect(is_file(public_path(ltrim($screenshot['src'], '/'))))->toBeTrue()
                ->and($screenshot['alt'])->not->toBeEmpty();
        }
    }
});
