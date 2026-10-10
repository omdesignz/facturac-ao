<?php

namespace App\Fiscal;

use App\Analytics\BillingSummaryQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/** Server-owned route metadata only; never a capability dispatcher. */
final class ReadOperationAudit
{
    /** @return array{event: string, properties: array<string, mixed>} */
    public static function denial(Request $request, bool $external): array
    {
        $v2 = $external && $request->is('api/integrations/v2', 'api/integrations/v2/*');
        $prefix = $external ? ($v2 ? 'integrations.v2.' : 'integrations.v1.') : 'api.v1.';
        $routeName = $request->route()?->getName();
        if ($routeName === null) {
            foreach (Route::getRoutes()->getRoutes() as $route) {
                if (in_array('GET', $route->methods(), true) && str_starts_with($route->getName() ?? '', $prefix) && $route->matches($request, includingMethod: false)) {
                    $routeName = $route->getName();
                    break;
                }
            }
        }
        $operation = match ($routeName) {
            $prefix.'analytics.billing.show' => 'analytics.billing.read',
            $prefix.'documents.agt-status.show' => 'documents.agt-status.read',
            $prefix.'documents.index' => 'documents.list',
            $prefix.'documents.show' => 'documents.read',
            $prefix.'customers.index' => 'customers.list',
            $prefix.'customers.show' => 'customers.read',
            $prefix.'catalogue.index' => 'catalogue.list',
            $prefix.'catalogue.show' => 'catalogue.read',
            default => null,
        };
        $family = match ($operation) {
            'documents.list', 'documents.read' => 'documents',
            'documents.agt-status.read' => 'documents.agt-status',
            'analytics.billing.read' => 'analytics.billing',
            'customers.list', 'customers.read' => 'customers',
            'catalogue.list', 'catalogue.read' => 'catalogue',
            default => $external ? 'integration' : 'documents',
        };

        return ['event' => $family.'.read.denied', 'properties' => $operation === null ? [] : [
            'operation' => $operation,
            ...($v2 ? ['capability_version' => 2, 'method' => $request->method()] : []),
            ...($operation === 'analytics.billing.read' ? ['metric_version' => BillingSummaryQuery::METRIC_VERSION] : []),
            ...(! in_array($family, ['documents', 'documents.agt-status', 'analytics.billing'], true) ? ['data_scope' => 'legal_entity_master'] : []),
        ]];
    }

    public static function isExternalPath(Request $request): bool
    {
        return $request->is('api/integrations/v1', 'api/integrations/v1/*', 'api/integrations/v2', 'api/integrations/v2/*');
    }

    public static function isQualifiedPath(Request $request): bool
    {
        return preg_match('~\Aapi/integrations/v2/workspaces/[^/]+/legal-entities/[^/]+/environments/[^/]+/documents/[^/]+/agt-status\z~', $request->decodedPath()) === 1;
    }

    public static function isBillingPath(Request $request): bool
    {
        return preg_match('~\Aapi/integrations/v2/workspaces/[^/]+/legal-entities/[^/]+/environments/[^/]+/analytics/billing-summary\z~', $request->decodedPath()) === 1;
    }

    public static function isMasterPath(Request $request): bool
    {
        return preg_match('~\Aapi/(?:integrations/)?v1/workspaces/[^/]+/legal-entities/[^/]+/environments/[^/]+/(?:customers|catalogue-items)(?:/[^/]+)?\z~', $request->path()) === 1;
    }
}
