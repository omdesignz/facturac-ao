<?php

test('a browser can leave an unavailable assistant through the branded error page', function () {
    config(['assistant.enabled' => true]);

    // The guarded connection refuses the test's open transaction, just as it
    // refuses an unsupported production driver before executing any AI work.
    $this->get('/assistant/workspaces/demo/legal-entities/demo/environments/production')
        ->assertStatus(503)
        ->assertViewIs('errors.503')
        ->assertSee('O assistente está indisponível')
        ->assertSee('Voltar a Hoje')
        ->assertDontSee('Estamos em manutenção')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Request-ID');
});

test('an unavailable assistant navigation leaves Inertia before rendering HTML', function () {
    config(['assistant.enabled' => true]);
    $url = '/assistant/workspaces/demo/legal-entities/demo/environments/production';

    $this->withHeaders(['X-Inertia' => 'true', 'Accept' => 'text/html, application/xhtml+xml'])
        ->get($url)
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', url($url))
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('the disabled assistant stays hidden and API errors remain JSON', function () {
    config(['assistant.enabled' => false]);
    $url = '/assistant/workspaces/demo/legal-entities/demo/environments/production';

    $this->get($url)->assertNotFound()->assertViewIs('errors.404');
    $this->getJson($url)->assertNotFound()->assertJsonPath('error.code', 'not_found')->assertJsonMissingPath('results');
    $this->postJson($url.'/interactions', [])->assertNotFound()->assertJsonPath('error.code', 'not_found')->assertJsonMissingPath('results');
});
