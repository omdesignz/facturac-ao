<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string OLD = 'Exportação SAF-T e consulta dos documentos emitidos';

    private const string NEW = 'Consulta dos documentos emitidos';

    /**
     * Run the migrations.
     *
     * Only the sales wording changes: electronic invoicing does not require
     * SAF-T, so the packages stop advertising it. What a plan includes lives in
     * its limits and is untouched, and a line staff have reworded is left alone.
     */
    public function up(): void
    {
        $this->replaceFeature(self::OLD, self::NEW);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->replaceFeature(self::NEW, self::OLD);
    }

    private function replaceFeature(string $from, string $to): void
    {
        foreach (['subscription_packages', 'subscription_plans'] as $table) {
            DB::table($table)->orderBy('id')->each(function (object $row) use ($table, $from, $to): void {
                $features = json_decode((string) $row->features, true);

                if (! is_array($features) || ! in_array($from, $features, true)) {
                    return;
                }

                DB::table($table)->where('id', $row->id)->update([
                    'features' => json_encode(
                        array_map(fn (mixed $feature): mixed => $feature === $from ? $to : $feature, $features),
                        JSON_UNESCAPED_UNICODE,
                    ),
                ]);
            });
        }
    }
};
