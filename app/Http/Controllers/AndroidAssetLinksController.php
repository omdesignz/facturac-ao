<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\ResolveAndroidPasskeyAssociation;
use Illuminate\Http\JsonResponse;

class AndroidAssetLinksController
{
    public function __invoke(ResolveAndroidPasskeyAssociation $association): JsonResponse
    {
        $target = $association->handle();

        abort_if($target === null, 404);

        return response()->json([[
            'relation' => ['delegate_permission/common.get_login_creds'],
            'target' => $target,
        ]]);
    }
}
