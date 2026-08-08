<?php

/*
 * The root used to send everyone to the dashboard, which was fine while every
 * visitor already had an account. It now answers with the public page instead;
 * the redirect survives only for people who are already signed in.
 *
 * Fuller coverage of both halves lives in LandingPageTest.
 */
test('the entry point is a page anyone can read', function () {
    $this->get(route('home'))->assertOk();
});
