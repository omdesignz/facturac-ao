<?php

use App\Models\User;
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
