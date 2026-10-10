<?php

namespace App\Fiscal;

use Illuminate\Support\Facades\DB;

/** The tenant tool ceiling of one governed interaction; it only narrows and never widens actor authority. */
final class AiToolCeiling
{
    private const SELECTION = ['deployment_id', 'root_control_id', 'mode', 'revision', 'entitlement_revision', 'profile_id', 'connection_id', 'credential_version_id'];

    /** @param list<string> $tools
     * @param  array<string, mixed>  $selection
     */
    private function __construct(private readonly int $workspaceId, private readonly array $tools,
        private readonly array $selection, private readonly ?\Closure $gates) {}

    /** @param (\Closure(\stdClass): void)|null $gates Route gates repeated on every recheck. */
    public static function admit(AssistantProviderInvocation $invocation, \stdClass $settings, ?\Closure $gates = null): ?self
    {
        $tools = self::tools($settings, $invocation->permissions());
        if ($tools === []) {
            return null;
        }

        return new self($invocation->context->execution->workspaceId, $tools, self::selection($settings), $gates);
    }

    /** @param list<string> $permissions
     * @return list<string>
     */
    public static function tools(\stdClass $settings, array $permissions): array
    {
        $tools = [];
        foreach (AssistantPlan::PERMISSIONS as $tool => $permission) {
            $column = TenantAiCapabilities::column($tool);
            if ($column !== null && property_exists($settings, $column) && self::flag($settings->{$column}) && in_array($permission, $permissions, true)) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    /** Exactly true; SQLite stores the same boolean as the integer one. */
    public static function flag(mixed $value): bool
    {
        return $value === true || ($value === 1 && DB::getDriverName() === 'sqlite');
    }

    /** Exactly false; SQLite stores the same boolean as the integer zero. */
    public static function cleared(mixed $value): bool
    {
        return $value === false || ($value === 0 && DB::getDriverName() === 'sqlite');
    }

    /** @return list<string> */
    public function admitted(): array
    {
        return $this->tools;
    }

    public function admits(string $tool): bool
    {
        return in_array($tool, $this->tools, true);
    }

    public function recheck(AssistantProviderInvocation $invocation): void
    {
        $settings = DB::table('tenant_ai_settings')->useWritePdo()->where('workspace_id', $this->workspaceId)->first();
        abort_unless($settings !== null && $invocation->context->execution->workspaceId === $this->workspaceId
            && self::selection($settings) === $this->selection && self::tools($settings, $invocation->permissions()) === $this->tools, 503);
        if ($this->gates !== null) {
            ($this->gates)($settings);
        }
    }

    /** @return array<string, mixed> */
    private static function selection(\stdClass $settings): array
    {
        $selection = [];
        foreach (self::SELECTION as $field) {
            $value = $settings->{$field} ?? null;
            $selection[$field] = in_array($field, ['revision', 'entitlement_revision'], true) ? (int) $value : $value;
        }

        return $selection;
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new \LogicException('Tool ceilings cannot be serialized.');
    }

    /** @return array<never, never> */
    public function __debugInfo(): array
    {
        return [];
    }

    private function __clone() {}
}
