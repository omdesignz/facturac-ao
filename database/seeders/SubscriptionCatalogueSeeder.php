<?php

namespace Database\Seeders;

use App\Models\SubscriptionPackage;
use App\Models\SubscriptionPlan;
use App\SubscriptionFeature;
use App\SubscriptionInterval;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SubscriptionCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (config('subscriptions.catalogue') as $code => $definition) {
                $limits = ['entitlement_version' => 1];
                $features = ['Documentos sem limite de quantidade', 'Exportação SAF-T e consulta dos documentos emitidos'];
                foreach (SubscriptionFeature::cases() as $feature) {
                    $limits[$feature->key()] = in_array($feature->value, $definition['features'], true);
                    if ($limits[$feature->key()]) {
                        $features[] = $feature->label();
                    }
                }

                $package = SubscriptionPackage::firstOrCreate(['code' => $code], [
                    'name' => $definition['name'], 'summary' => $definition['summary'],
                    'features' => $features, 'limits' => $limits,
                    'trial_days' => config('subscriptions.trial_days'), 'currency_code' => 'AOA',
                    'is_active' => true, 'is_featured' => $code === 'negocio',
                    'sort_order' => array_search($code, array_keys(config('subscriptions.catalogue')), true) + 1,
                ]);

                // Re-running installation must never overwrite staff pricing or sold plans.
                if (! $package->wasRecentlyCreated) {
                    continue;
                }

                foreach (SubscriptionInterval::ordered() as $interval) {
                    $multiplier = match ($interval) {
                        SubscriptionInterval::Monthly => 100,
                        SubscriptionInterval::Quarterly => 285,
                        SubscriptionInterval::Semiannual => 540,
                        SubscriptionInterval::Annual => 1000,
                    };
                    SubscriptionPlan::create([
                        'subscription_package_id' => $package->id, 'code' => $code.'-'.$interval->value,
                        'name' => $package->name, 'summary' => $package->summary,
                        'features' => $features, 'limits' => $limits,
                        'trial_days' => $package->trial_days, 'currency_code' => 'AOA',
                        'amount_minor' => intdiv($definition['monthly_amount_minor'] * $multiplier, 100),
                        'interval' => $interval, 'is_active' => true,
                        'sort_order' => $package->sort_order * 10 + $interval->position(),
                    ]);
                }
            }
        });
    }
}
