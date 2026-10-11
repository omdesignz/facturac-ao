<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Packages (pacotes) group the plans a customer can choose between.
 *
 * A package is what staff manage and what customers compare; each enabled
 * payment period of a package remains one subscription_plans row, so charges,
 * renewals and fulfilment keep working per plan and nothing already sold moves.
 *
 * Plans that existed before this table are given a package of their own, so
 * the screens that now list packages do not lose a plan that is on sale.
 */
return new class extends Migration
{
    private const CODE_LENGTH = 48;

    public function up(): void
    {
        Schema::create('subscription_packages', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', self::CODE_LENGTH)->unique();
            $table->string('name', 80);
            $table->string('summary', 500)->nullable();
            $table->json('features')->nullable();
            $table->json('limits')->nullable();
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->char('currency_code', 3)->default('AOA');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->foreignId('subscription_package_id')
                ->nullable()
                ->constrained('subscription_packages')
                ->restrictOnDelete();
        });

        /*
         * A package has at most one plan per interval. Partial, because plans
         * created before packages existed are unlinked until the backfill below.
         */
        DB::statement(
            'CREATE UNIQUE INDEX subscription_plans_one_per_package_interval '
            .'ON subscription_plans (subscription_package_id, "interval") '
            .'WHERE subscription_package_id IS NOT NULL',
        );

        $this->backfillPackages();
    }

    /**
     * Give every plan that has no package a package made from it.
     *
     * Public so a test can run it against rows inserted without a package.
     */
    public function backfillPackages(): void
    {
        $now = now();

        DB::table('subscription_plans')
            ->whereNull('subscription_package_id')
            ->orderBy('id')
            ->each(function (object $plan) use ($now): void {
                $packageId = DB::table('subscription_packages')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'code' => $this->uniqueCode((string) $plan->code),
                    'name' => Str::limit((string) $plan->name, 80, ''),
                    'summary' => $plan->summary,
                    'features' => $plan->features,
                    'limits' => $plan->limits,
                    'trial_days' => $plan->trial_days,
                    'currency_code' => $plan->currency_code,
                    'is_active' => $plan->is_active,
                    'is_featured' => false,
                    'sort_order' => $plan->sort_order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('subscription_plans')
                    ->where('id', $plan->id)
                    ->update(['subscription_package_id' => $packageId]);
            });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS subscription_plans_one_per_package_interval');

        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('subscription_package_id');
        });

        Schema::dropIfExists('subscription_packages');
    }

    /** A lowercase slug no longer than the column, not yet taken by another package. */
    private function uniqueCode(string $source): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($source)), '-');
        $base = rtrim(substr($slug === '' ? 'pacote' : $slug, 0, self::CODE_LENGTH), '-');
        $code = $base;
        $attempt = 1;

        while (DB::table('subscription_packages')->where('code', $code)->exists()) {
            $attempt++;
            $suffix = '-'.$attempt;
            $code = rtrim(substr($base, 0, self::CODE_LENGTH - strlen($suffix)), '-').$suffix;
        }

        return $code;
    }
};
