<?php

namespace App\Http\Controllers\Concerns;

use App\Models\LegalEntity;
use App\Models\Workspace;
use Illuminate\Http\Request;

/**
 * The company a point-of-sale request is about, resolved the way every other
 * controller does: the workspace the middleware settled on, and its oldest
 * legal entity.
 */
trait ResolvesPosCompany
{
    private function legalEntity(Request $request): ?LegalEntity
    {
        $workspace = $request->attributes->get('currentWorkspace');

        if (! $workspace instanceof Workspace) {
            return null;
        }

        return $workspace->legalEntities()->oldest('id')->first();
    }

    /** The company, or a 404: a request with no company has nothing to act on. */
    private function legalEntityOrFail(Request $request): LegalEntity
    {
        $legalEntity = $this->legalEntity($request);
        abort_unless($legalEntity instanceof LegalEntity, 404);

        return $legalEntity;
    }
}
