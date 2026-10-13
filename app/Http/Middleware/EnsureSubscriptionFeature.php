<?php

namespace App\Http\Middleware;

use App\Billing\SubscriptionAccess;
use App\Models\Workspace;
use App\SubscriptionFeature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureSubscriptionFeature
{
    public function __construct(private SubscriptionAccess $access) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $feature = $this->feature($request);
        if ($feature !== null) {
            $workspace = $request->attributes->get('currentWorkspace');
            abort_unless($workspace instanceof Workspace, 403);
            $this->access->require($workspace->id, $feature);
        }

        return $next($request);
    }

    private function feature(Request $request): ?SubscriptionFeature
    {
        // Historical records, downloads, SAF-T, cancellation and shift closure remain accessible.
        return match (true) {
            $request->routeIs('pos.show', 'pos.sessions.store', 'pos.sales.store', 'pos.registers.store', 'pos.registers.update') => SubscriptionFeature::Pos,
            $request->routeIs('stock.movements.store') => SubscriptionFeature::Inventory,
            $request->routeIs('recurring.store', 'recurring.update', 'recurring.run') => SubscriptionFeature::Recurring,
            $request->routeIs('transport-documents.create', 'transport-documents.store', 'transport-documents.update', 'transport-documents.issue') => SubscriptionFeature::Transport,
            $request->routeIs('transaction-reports.preview', 'transaction-reports.download') => SubscriptionFeature::Reports,
            $request->routeIs('invoices.create', 'invoices.store', 'invoices.update', 'invoices.issue', 'quotes.create', 'quotes.store', 'quotes.update', 'quotes.convert') => SubscriptionFeature::Invoicing,
            default => null,
        };
    }
}
