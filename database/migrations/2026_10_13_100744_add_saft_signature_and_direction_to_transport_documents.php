<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_documents', function (Blueprint $table): void {
            $table->string('document_hash', 172)->nullable()->change();
            $table->string('movement_direction', 8)->nullable();
            $table->string('signature_algorithm', 32)->nullable();
            $table->text('signature_public_key')->nullable();
            $table->string('previous_signature', 172)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transport_documents', function (Blueprint $table): void {
            $table->dropColumn(['movement_direction', 'signature_algorithm', 'signature_public_key', 'previous_signature']);
            // Keep capacity for already-issued signatures during rollback.
        });
    }
};
