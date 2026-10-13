<?php

namespace App\Exceptions;

use App\SubscriptionFeature;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SubscriptionRequired extends HttpException implements ShouldntReport
{
    public function __construct(public readonly SubscriptionFeature $feature)
    {
        parent::__construct(403, 'O plano actual não inclui '.$feature->label().'. Escolha um plano em Assinatura para continuar.');
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => $this->getMessage(),
                'code' => 'SUBSCRIPTION_REQUIRED',
                'feature' => $this->feature->value,
                'billing_url' => route('billing.show'),
            ], 403)->header('Cache-Control', 'private, no-store');
        }

        return redirect()->route('billing.show')->with('error', $this->getMessage());
    }
}
