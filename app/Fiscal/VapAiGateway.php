<?php

namespace App\Fiscal;

/** Application-owned intent boundary; never an SDK or general-purpose inference client. */
interface VapAiGateway
{
    public function infer(AiInferenceRequest $request): AiInferenceResult;
}
