<?php

use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Every status Laravel ships a default view for. If a view is missing here,
 * the user gets Laravel's generic page instead of ours.
 */
dataset('statuses', [401, 402, 403, 404, 419, 429, 500, 503]);

test('a custom view exists for every error status', function (int $status) {
    expect(view()->exists("errors.{$status}"))->toBeTrue();
})->with('statuses');

test('each error page renders in Portuguese and carries the brand', function (int $status) {
    $html = view("errors.{$status}")->render();

    expect($html)->toContain('lang="pt-AO"')
        ->and($html)->toContain('facturac.ao')
        ->and($html)->toContain("Erro {$status}")
        ->and($html)->toContain('Emitida. Validada. Paga.')
        ->and($html)->not->toContain('Feito para humanos');
})->with('statuses');

test('error pages draw the wordmark inline so a broken build cannot hide it', function (int $status) {
    $html = view("errors.{$status}")->render();

    // Outlines, not text in a font and not an image: either would need
    // something this page is not allowed to depend on.
    expect($html)->toContain('aria-label="facturac.ao"')
        ->and($html)->toContain('<path fill="currentColor"')
        ->and($html)->not->toContain('rotate(-8')
        ->and($html)->not->toContain('<img');
})->with('statuses');

test('error pages do not depend on the asset build', function (int $status) {
    $html = view("errors.{$status}")->render();

    // A broken Vite manifest is a common cause of a 500; an error page that
    // needs the build would fail to render for exactly that failure.
    expect($html)->not->toContain('/build/')
        ->and($html)->not->toContain('@vite');
})->with('statuses');

test('a missing page returns our 404 rather than the framework default', function () {
    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user)
        ->get('/uma-pagina-que-nao-existe')
        ->assertNotFound()
        ->assertSee('Não encontrámos esta página', false);
});

test('a forbidden response renders the custom 403', function () {
    Route::middleware('web')->get('/__test-403', fn () => abort(403));

    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user)
        ->get('/__test-403')
        ->assertForbidden()
        ->assertSee('Esta área não é para o seu acesso', false);
});

test('a server error renders the custom 500 page', function () {
    Route::middleware('web')->get('/__test-500', function (): never {
        throw new HttpException(500);
    });

    $this->get('/__test-500')
        ->assertStatus(500)
        ->assertSee('Algo correu mal do nosso lado', false);
});

test('api routes still receive JSON rather than the HTML error page', function () {
    $this->getJson('/api/nada-aqui')
        ->assertNotFound()
        ->assertHeader('content-type', 'application/json');
});

/**
 * What each status prints as its stamp, the real word a screen reader hears
 * instead, and where its primary pill goes. The destinations are the ones the
 * page can name without a session or the database; 402 is the exception by
 * design, because its whole point is the billing screen.
 *
 * @return array<int, array{stamp: string, word: string, primary: string}>
 */
function errorPageExpectations(): array
{
    return [
        401 => ['stamp' => 'autenticac<span class="dot"></span>ao', 'word' => 'Autenticação', 'primary' => '/login'],
        402 => ['stamp' => 'subscric<span class="dot"></span>ao', 'word' => 'Subscrição', 'primary' => '/settings/billing'],
        403 => ['stamp' => 'autorizac<span class="dot"></span>ao', 'word' => 'Autorização', 'primary' => '/'],
        404 => ['stamp' => 'direcc<span class="dot"></span>ao', 'word' => 'Direcção', 'primary' => '/'],
        419 => ['stamp' => 'expirac<span class="dot"></span>ao', 'word' => 'Expiração', 'primary' => ''],
        429 => ['stamp' => 'moderac<span class="dot"></span>ao', 'word' => 'Moderação', 'primary' => ''],
        500 => ['stamp' => 'interrupc<span class="dot"></span>ao', 'word' => 'Interrupção', 'primary' => ''],
        503 => ['stamp' => 'manutenc<span class="dot"></span>ao', 'word' => 'Manutenção', 'primary' => ''],
    ];
}

function renderErrorPage(int $status, ?HttpException $exception = null): string
{
    return view("errors.{$status}", $exception === null ? [] : ['exception' => $exception])->render();
}

test('each status sets its own stamp with the gold full stop and says the real word once', function (int $status) {
    $expected = errorPageExpectations()[$status];
    $html = renderErrorPage($status);

    // The decorative split is hidden from assistive technology; the proper
    // spelling is the only thing it reads.
    expect($html)->toContain('<span aria-hidden="true">'.$expected['stamp'].'</span>')
        ->and($html)->toContain('<span class="sr-only">'.$expected['word'].'</span>')
        ->and(substr_count($html, 'class="sr-only"'))->toBe(1)
        ->and($html)->toContain('background: var(--gold)')
        ->and($html)->toContain('white-space: nowrap');
})->with('statuses');

test('each status has exactly one h1 and one main landmark', function (int $status) {
    $html = renderErrorPage($status);

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and(substr_count($html, '<main'))->toBe(1)
        ->and($html)->toContain('name="robots" content="noindex, nofollow"');
})->with('statuses');

test('the primary action is a link that does not need the session', function (int $status) {
    $html = renderErrorPage($status);

    expect(preg_match('/<a class="pill pill--ink" href="([^"]*)"/', $html, $primary))->toBe(1)
        ->and($primary[1])->toBe(errorPageExpectations()[$status]['primary'])
        ->and($html)->toContain('<a class="home" href="/">');
})->with('statuses');

test('the back action is a real link that script only upgrades', function (int $status) {
    $html = renderErrorPage($status);

    if (! in_array($status, [403, 404], true)) {
        expect($html)->not->toContain('<a class="pill pill--outline" href="/" data-back>');

        return;
    }

    expect($html)->toContain('<a class="pill pill--outline" href="/" data-back>Voltar atrás</a>')
        ->and($html)->toContain('window.history.back()');
})->with('statuses');

test('error pages load nothing: no external address, stylesheet, script file or font', function (int $status) {
    $html = renderErrorPage($status, in_array($status, [429, 503], true) ? new HttpException($status, '', null, ['Retry-After' => '30']) : null);

    expect($html)->not->toMatch('#https?:#i')
        ->and($html)->not->toContain('//cdn')
        ->and($html)->not->toContain('@vite')
        ->and($html)->not->toContain('@font-face')
        ->and($html)->not->toContain('@import')
        ->and($html)->not->toContain('url(')
        ->and($html)->not->toMatch('#<link[^>]+rel="stylesheet"#i')
        ->and($html)->not->toMatch('#<script[^>]+src=#i');
})->with('statuses');

test('error pages never query the database', function (int $status) {
    DB::enableQueryLog();

    renderErrorPage($status);

    expect(DB::getQueryLog())->toBe([]);
})->with('statuses');

test('actions are pills, not rounded rectangles', function (int $status) {
    $html = renderErrorPage($status);

    expect(preg_match('/\.pill \{[^}]*\}/s', $html, $pill))->toBe(1)
        ->and($pill[0])->toContain('border-radius: 9999px')
        ->and($pill[0])->toContain('min-block-size: 2.75rem')
        ->and($html)->not->toContain('border-radius: 0.75rem');
})->with('statuses');

test('the type uses the same stacks as the app', function () {
    $html = renderErrorPage(404);

    expect($html)->toContain("'Hanken Grotesk'")
        ->and($html)->toContain("'Century Gothic'")
        ->and($html)->toContain("'IBM Plex Mono'");
});

test('a server error never prints the exception', function () {
    $html = renderErrorPage(500, new HttpException(500, 'SQLSTATE[08006] secret in /var/www/app/Thing.php'));

    expect($html)->not->toContain('SQLSTATE')
        ->and($html)->not->toContain('secret')
        ->and($html)->not->toContain('/var/www')
        ->and($html)->not->toContain('HttpException')
        ->and($html)->not->toContain('Thing.php');
});

test('an unexpected exception renders the 500 page without its message', function () {
    config(['app.debug' => false]);

    Route::middleware('web')->get('/__test-500-leak', function (): never {
        throw new RuntimeException('internal secret detail');
    });

    $this->get('/__test-500-leak')
        ->assertStatus(500)
        ->assertSee('Algo correu mal do nosso lado', false)
        ->assertDontSee('internal secret detail')
        ->assertDontSee('RuntimeException');
});

test('a server error offers a reference with the time and the request id', function () {
    Context::add('request_id', '7f9c2b1e-4a3d-4c55-9e0a-123456789abc');

    $html = renderErrorPage(500);

    expect($html)->toContain('Referência para o apoio')
        ->and($html)->toContain('7f9c2b1e-4a3d-4c55-9e0a-123456789abc')
        ->and($html)->toMatch('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \(Luanda\)/');
});

test('a server error still renders when there is no request id, and never prints a doubtful one', function () {
    Context::forget('request_id');

    expect(renderErrorPage(500))->toContain('Referência para o apoio');

    Context::add('request_id', '<script>alert(1)</script>');

    expect(renderErrorPage(500))->not->toContain('<script>alert(1)</script>');
});

test('only the server error carries a reference', function (int $status) {
    expect(renderErrorPage($status))->not->toContain('Referência para o apoio');
})->with([401, 402, 403, 404, 419, 429, 503]);

test('429 and 503 say when to try again only when a retry value is present', function (int $status) {
    $with = renderErrorPage($status, new HttpException($status, '', null, ['Retry-After' => '30']));
    $without = renderErrorPage($status, new HttpException($status));

    expect($with)->toContain('Volte a tentar dentro de 30 segundos.')
        ->and($without)->not->toContain('Volte a tentar')
        ->and(renderErrorPage($status))->not->toContain('Volte a tentar');
})->with([429, 503]);

test('a retry interval is said in the unit a person would use', function (int $seconds, string $said) {
    expect(renderErrorPage(429, new HttpException(429, '', null, ['Retry-After' => (string) $seconds])))
        ->toContain("Volte a tentar dentro de {$said}.");
})->with([
    'one second' => [1, '1 segundo'],
    'a minute and a half' => [90, '2 minutos'],
    'exactly a minute' => [60, '1 minuto'],
    'two hours' => [7200, '2 horas'],
]);

test('a retry date is turned into a wait, and nonsense is ignored', function () {
    $date = gmdate('D, d M Y H:i:s', time() + 125).' GMT';

    expect(renderErrorPage(429, new HttpException(429, '', null, ['Retry-After' => $date])))
        ->toContain('Volte a tentar dentro de ')
        ->and(renderErrorPage(429, new HttpException(429, '', null, ['Retry-After' => 'soon'])))->not->toContain('Volte a tentar')
        ->and(renderErrorPage(429, new HttpException(429, '', null, ['Retry-After' => '0'])))->not->toContain('Volte a tentar')
        ->and(renderErrorPage(429, new HttpException(429, '', null, ['Retry-After' => '-5'])))->not->toContain('Volte a tentar');
});

test('maintenance reloads itself only when a retry interval is given', function () {
    $with = renderErrorPage(503, new HttpException(503, '', null, ['Retry-After' => '60']));
    $without = renderErrorPage(503, new HttpException(503));

    expect($with)->toContain('<meta http-equiv="refresh" content="60">')
        ->and($without)->not->toContain('http-equiv')
        ->and(renderErrorPage(429, new HttpException(429, '', null, ['Retry-After' => '60'])))->not->toContain('http-equiv');
});

test('the retry value reaches the page through the framework', function () {
    Route::middleware('web')->get('/__test-429', fn () => abort(429, '', ['Retry-After' => 45]));
    Route::middleware('web')->get('/__test-503', fn () => abort(503, '', ['Retry-After' => 120]));

    $this->get('/__test-429')->assertStatus(429)->assertSee('Volte a tentar dentro de 45 segundos.', false);
    $this->get('/__test-503')->assertStatus(503)->assertSee('Volte a tentar dentro de 2 minutos.', false)->assertSee('http-equiv="refresh" content="120"', false);
});

test('support is offered from the configured address on 403, 500 and 503 only', function (int $status) {
    config(['platform.settings.support_email' => 'apoio@exemplo.ao']);

    $html = renderErrorPage($status);

    if (in_array($status, [403, 500, 503], true)) {
        expect($html)->toContain('href="mailto:apoio@exemplo.ao?subject=')
            ->and($html)->toContain('Escrever ao apoio');

        return;
    }

    expect($html)->not->toContain('mailto:')->and($html)->not->toContain('Escrever ao apoio');
})->with('statuses');

test('the support link is left out when there is no usable address', function () {
    config(['platform.settings.support_email' => null]);
    expect(renderErrorPage(500))->not->toContain('mailto:');

    config(['platform.settings.support_email' => 'not an address']);
    expect(renderErrorPage(500))->not->toContain('mailto:');
});

test('the reference travels in the support message', function () {
    Context::add('request_id', '7f9c2b1e-4a3d-4c55-9e0a-123456789abc');
    config(['platform.settings.support_email' => 'apoio@exemplo.ao']);

    expect(renderErrorPage(500))->toContain('&amp;body=Refer')
        ->and(renderErrorPage(500))->toContain('7f9c2b1e-4a3d-4c55-9e0a-123456789abc');
});

test('the shell fits a phone and respects the reader', function () {
    $html = renderErrorPage(404);

    expect($html)->toContain('min-block-size: 100dvh')
        ->and($html)->toContain('safe-area-inset-top')
        ->and($html)->toContain('text-wrap: balance')
        ->and($html)->toContain('text-wrap: pretty')
        ->and($html)->toContain('(hover: hover) and (pointer: fine)')
        ->and($html)->toContain(':focus-visible')
        ->and($html)->toContain('prefers-color-scheme: dark')
        ->and($html)->toContain("[data-theme='dark']")
        ->and($html)->toContain("localStorage.getItem('vap-appearance')");
});
