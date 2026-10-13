<?php

namespace App\Actions;

use App\Models\SubscriptionPackage;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\SubscriptionFeature;
use App\SubscriptionInterval;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates or updates a package and keeps its plan rows in step.
 *
 * Each enabled payment period of a package is a SubscriptionPlan, so checkout,
 * charges and renewals keep working per plan. A plan is never deleted from
 * here: a period that is dropped or switched off is only made unavailable, so
 * the subscriptions and charges that point at it stay intact. A charge stores
 * its own amount, so a new price only reaches checkouts started afterwards.
 */
class SaveSubscriptionPackage
{
    /** The longest a plan code may be, as the column allows. */
    private const PLAN_CODE_LENGTH = 64;

    /**
     * @param  array{
     *     code?: string,
     *     entitlements?: list<string>,
     *     name: string,
     *     summary: string|null,
     *     features: list<string>,
     *     trial_days: int,
     *     currency_code: string,
     *     is_active: bool,
     *     is_featured: bool,
     *     sort_order: int,
     *     periods: list<array{interval: SubscriptionInterval, amount_minor: int, is_active: bool}>
     * }  $data
     */
    public function execute(?SubscriptionPackage $package, array $data, User $actor): SubscriptionPackage
    {
        $periods = $this->periods($data);

        if ($data['is_active'] && ! in_array(true, array_column($periods, 'is_active'), true)) {
            throw ValidationException::withMessages([
                'periods' => 'Um pacote activo precisa de pelo menos um período de pagamento activo.',
            ]);
        }

        return DB::transaction(function () use ($package, $data, $periods, $actor): SubscriptionPackage {
            $attributes = [
                'name' => $data['name'],
                'summary' => $data['summary'],
                'features' => $data['features'],
                'trial_days' => $data['trial_days'],
                'currency_code' => $data['currency_code'],
                'is_active' => $data['is_active'],
                'is_featured' => $data['is_featured'],
                'sort_order' => $data['sort_order'],
            ];

            if (isset($data['entitlements']) || $package === null) {
                $enabled = $data['entitlements'] ?? [SubscriptionFeature::Invoicing->value];
                $attributes['limits'] = ['entitlement_version' => 1];
                foreach (SubscriptionFeature::cases() as $feature) {
                    $attributes['limits'][$feature->key()] = $feature === SubscriptionFeature::Invoicing || in_array($feature->value, $enabled, true);
                }
                $attributes['trial_days'] = (int) config('subscriptions.trial_days', 14);
                if (isset($data['entitlements'])) {
                    $labels = array_map(fn (SubscriptionFeature $feature): string => $feature->label(), SubscriptionFeature::cases());
                    $included = array_map(fn (SubscriptionFeature $feature): string => $feature->label(), array_filter(SubscriptionFeature::cases(), fn (SubscriptionFeature $feature): bool => $attributes['limits'][$feature->key()] === true));
                    $attributes['features'] = array_values(array_unique([
                        'Documentos sem limite de quantidade',
                        'Exportação SAF-T e consulta dos documentos emitidos',
                        ...$included,
                        ...array_diff($data['features'], $labels),
                    ]));
                    if (count($attributes['features']) > 20) {
                        throw ValidationException::withMessages(['features' => 'Use no máximo 20 linhas, incluindo as funcionalidades seleccionadas.']);
                    }
                }
            }

            if ($package instanceof SubscriptionPackage) {
                $locked = SubscriptionPackage::query()->whereKey($package->id)->lockForUpdate()->firstOrFail();
                $locked->fill($attributes)->save();
            } else {
                $code = $data['code'] ?? throw new InvalidArgumentException('A new package needs a code.');
                $locked = SubscriptionPackage::query()->create(['code' => $code, ...$attributes]);
            }

            if ($locked->is_featured) {
                SubscriptionPackage::query()
                    ->whereKeyNot($locked->id)
                    ->where('is_featured', true)
                    ->update(['is_featured' => false]);
            }

            $this->synchronisePlans($locked, $periods, $actor);

            return $locked->refresh();
        }, 5);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{interval: SubscriptionInterval, amount_minor: int, is_active: bool}>
     */
    private function periods(array $data): array
    {
        $seen = [];
        $periods = [];

        /** @var list<array{interval: SubscriptionInterval, amount_minor: int, is_active: bool}> $rows */
        $rows = $data['periods'];

        foreach ($rows as $period) {
            if (isset($seen[$period['interval']->value])) {
                throw ValidationException::withMessages([
                    'periods' => 'Cada período de pagamento só pode aparecer uma vez.',
                ]);
            }

            if ($period['amount_minor'] <= 0) {
                throw ValidationException::withMessages([
                    'periods' => 'O preço de cada período tem de ser superior a zero.',
                ]);
            }

            $seen[$period['interval']->value] = true;
            $periods[] = $period;
        }

        return $periods;
    }

    /**
     * @param  list<array{interval: SubscriptionInterval, amount_minor: int, is_active: bool}>  $periods
     */
    private function synchronisePlans(SubscriptionPackage $package, array $periods, User $actor): void
    {
        $plans = SubscriptionPlan::query()
            ->where('subscription_package_id', $package->id)
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (SubscriptionPlan $plan): string => $plan->interval->value);
        $requested = collect($periods)->keyBy(fn (array $period): string => $period['interval']->value);
        $changes = [];

        foreach (SubscriptionInterval::ordered() as $interval) {
            $plan = $plans->get($interval->value);
            $period = $requested->get($interval->value);

            if ($period === null) {
                if ($plan instanceof SubscriptionPlan && $plan->is_active) {
                    $before = $this->snapshot($plan);
                    $plan->forceFill(['is_active' => false])->save();
                    $changes[$interval->value] = ['from' => $before, 'to' => $this->snapshot($plan)];
                }

                continue;
            }

            $before = $plan instanceof SubscriptionPlan ? $this->snapshot($plan) : null;
            $plan ??= new SubscriptionPlan([
                'subscription_package_id' => $package->id,
                'code' => $this->planCode($package, $interval),
                'interval' => $interval,
            ]);

            $plan->fill([
                'name' => $package->name,
                'summary' => $package->summary,
                'features' => $package->features,
                'limits' => $package->limits,
                'trial_days' => $package->trial_days,
                'currency_code' => $package->currency_code,
                'amount_minor' => $period['amount_minor'],
                'is_active' => $package->is_active && $period['is_active'],
                'sort_order' => $package->sort_order * 10 + $interval->position(),
            ])->save();

            $after = $this->snapshot($plan);

            if ($before !== $after) {
                $changes[$interval->value] = ['from' => $before, 'to' => $after];
            }
        }

        if ($changes !== []) {
            activity('subscription-package')
                ->causedBy($actor)
                ->performedOn($package)
                ->event('periods-synchronised')
                ->withProperties(['periods' => $changes])
                ->log('Períodos de pagamento do pacote actualizados.');
        }
    }

    /**
     * What a price history needs to know about a plan row.
     *
     * @return array{amount_minor: int, currency_code: string, is_active: bool}
     */
    private function snapshot(SubscriptionPlan $plan): array
    {
        return [
            'amount_minor' => $plan->amount_minor,
            'currency_code' => $plan->currency_code,
            'is_active' => $plan->is_active,
        ];
    }

    /** "{package}-{interval}", made unique and kept inside the column. */
    private function planCode(SubscriptionPackage $package, SubscriptionInterval $interval): string
    {
        $base = substr($package->code.'-'.$interval->value, 0, self::PLAN_CODE_LENGTH);
        $code = $base;
        $attempt = 1;

        while (SubscriptionPlan::query()->where('code', $code)->exists()) {
            $attempt++;
            $suffix = '-'.$attempt;
            $code = rtrim(substr($base, 0, self::PLAN_CODE_LENGTH - strlen($suffix)), '-').$suffix;
        }

        return $code;
    }
}
