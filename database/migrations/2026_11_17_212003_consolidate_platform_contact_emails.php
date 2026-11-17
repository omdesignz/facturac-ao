<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $retired = ['reclamacoes@vapsolucoes.ao', 'privacidade@vapsolucoes.ao'];
        $email = 'geral@vapsolucoes.ao';

        DB::table('platform_settings')->whereIn('key', ['support_email', 'complaints_email', 'data_protection_email'])
            ->whereIn('value', $retired)->update(['value' => $email, 'updated_at' => now()]);
        Cache::forget('platform-settings');

        foreach (DB::table('legal_documents')->whereNotNull('published_at')->where('effective_at', '<=', now())->get()->groupBy('type') as $documents) {
            $current = $documents->sortByDesc('version')->first();
            $body = str_replace($retired, $email, $current->body);
            if ($body === $current->body) {
                continue;
            }

            DB::table('legal_documents')->insert([
                'public_id' => strtolower((string) Str::ulid()),
                'type' => $current->type,
                'version' => (int) DB::table('legal_documents')->where('type', $current->type)->max('version') + 1,
                'title' => $current->title,
                'summary' => $current->summary,
                'body' => $body,
                'effective_at' => now(),
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Contact corrections and published legal versions remain valid on rollback.
    }
};
