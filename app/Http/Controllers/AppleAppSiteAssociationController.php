<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Tells Apple which iPhone app may use this site's passkeys. Without it the app
 * cannot create or use a passkey for this domain at all.
 */
class AppleAppSiteAssociationController
{
    public function __invoke(): JsonResponse
    {
        $appId = config('services.ios.app_id');

        abort_unless(is_string($appId) && $appId !== '', 404);

        return response()->json(['webcredentials' => ['apps' => [$appId]]]);
    }
}
