<?php

namespace App\Http\Controllers;

use App\Fiscal\AssistantExecutionGuard;
use App\Fiscal\AssistantInteraction;
use App\Fiscal\AssistantInteractionContext;
use App\Fiscal\AssistantJson;
use App\Fiscal\AssistantProviderLedger;
use App\Fiscal\AssistantProviderProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AssistantController
{
    public function __construct(private readonly AssistantInteraction $interaction, private readonly AssistantProviderLedger $provider) {}

    public function show(Request $request): Response
    {
        $context = AssistantInteractionContext::resolve($request);
        $request->attributes->set('assistant_context', $context);

        return Inertia::render('Assistant/Index', ['context' => $context->publicContext(), 'provider' => $this->provider->notice($context)]);
    }

    public function acknowledgement(Request $request): JsonResponse
    {
        $context = AssistantInteractionContext::resolve($request);
        $request->attributes->set('assistant_context', $context);
        $data = AssistantJson::object($request->attributes->get('assistant_json'), 1024, 422);
        AssistantJson::keys($data, ['policy', 'acknowledged'], 422);
        abort_unless($data['policy'] === AssistantProviderProfile::POLICY && is_bool($data['acknowledged']), 422);
        $this->provider->acknowledge($context, $data['acknowledged']);

        return response()->json(['context' => $context->publicContext(), 'provider' => $this->provider->notice($context)]);
    }

    public function store(Request $request): JsonResponse
    {
        $context = AssistantInteractionContext::resolve($request);
        $request->attributes->set('assistant_context', $context);
        $guard = $request->attributes->get('assistant_guard');
        abort_unless($guard instanceof AssistantExecutionGuard, 503);

        return response()->json($this->interaction->executeJson($context, $request->attributes->get('assistant_json'), $guard));
    }
}
