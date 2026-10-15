<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_document_lines', function (Blueprint $table): void {
            $table->json('order_references')->nullable();
            $table->unique(['id', 'workspace_id', 'legal_entity_id'], 'transport_lines_tenant_unique');
        });
        Schema::table('fiscal_document_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('origin_transport_line_id')->nullable();
            $table->foreign(['origin_transport_line_id', 'workspace_id', 'legal_entity_id'], 'fiscal_lines_transport_origin_fk')
                ->references(['id', 'workspace_id', 'legal_entity_id'])->on('transport_document_lines')->restrictOnDelete();
            $table->index('origin_transport_line_id');
            $table->json('order_references')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_document_lines', function (Blueprint $table): void {
            // SQLite names a foreign key by its columns, not by the name it was given.
            $table->dropForeign(DB::getDriverName() === 'sqlite'
                ? ['origin_transport_line_id', 'workspace_id', 'legal_entity_id']
                : 'fiscal_lines_transport_origin_fk');
            $table->dropIndex(['origin_transport_line_id']);
            $table->dropColumn('origin_transport_line_id');
            $table->dropColumn('order_references');
        });
        Schema::table('transport_document_lines', function (Blueprint $table): void {
            $table->dropUnique('transport_lines_tenant_unique');
            $table->dropColumn('order_references');
        });
    }
};
