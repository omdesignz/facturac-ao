<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The company the app's figures belong to, chosen as the web pages choose it.
 *
 * Setting a company up happens on the web, so a workspace without one gets a
 * code the app can explain rather than a redirect it cannot follow.
 */
class EnsureMobileCompany
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->attributes->get('currentWorkspace');

        // The account can move to another company from the web or another phone.
        // A screen built for the old one must not read or write in the new one.
        $expected = $request->header('X-Company');

        if ($expected !== null && $workspace instanceof Workspace && $workspace->public_id !== $expected) {
            return response()->json(['error' => [
                'code' => 'COMPANY_CHANGED',
                'message' => 'Esta conta passou para outra empresa. Nada foi guardado.',
            ]], Response::HTTP_CONFLICT);
        }

        $legalEntity = $workspace instanceof Workspace
            ? $workspace->legalEntities()->oldest('id')->first()
            : null;

        if ($legalEntity === null) {
            return response()->json(['error' => [
                'code' => 'COMPANY_NOT_CONFIGURED',
                'message' => 'Conclua os dados fiscais da empresa em facturac.ao para continuar.',
            ]], Response::HTTP_CONFLICT);
        }

        $request->attributes->set('currentLegalEntity', $legalEntity);

        return $next($request);
    }
}
