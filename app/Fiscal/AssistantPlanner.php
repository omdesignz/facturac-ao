<?php

namespace App\Fiscal;

/** Planning is isolated from execution; business results are never planner input. */
interface AssistantPlanner
{
    /** @param array{question: string, references: list<array{kind: string, public_id: string}>, current_month: string, schema_version: int, tools: array<string, array<string, mixed>>} $input */
    public function plan(#[\SensitiveParameter] array $input, ?AssistantProviderInvocation $invocation = null): string;
}
