<?php

test('redirects the entry point to the phase zero dashboard', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('dashboard'));
});
