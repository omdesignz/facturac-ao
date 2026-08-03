<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('fiscal_series_id')->nullable()->after('customer_id');
            $table->unsignedBigInteger('agt_connection_id')->nullable()->after('fiscal_series_id');
            $table->unsignedBigInteger('issued_by_user_id')->nullable()->after('updated_by_user_id');
            $table->unsignedBigInteger('issue_sequence')->nullable()->after('document_no');
            $table->char('signable_payload_sha256', 64)->nullable()->after('calculation_sha256');
            $table->char('document_payload_sha256', 64)->nullable()->after('signable_payload_sha256');
            $table->longText('document_jws')->nullable()->after('document_payload_sha256');
            $table->string('software_product_id')->nullable()->after('document_jws');
            $table->string('software_product_version', 64)->nullable()->after('software_product_id');
            $table->string('software_validation_number')->nullable()->after('software_product_version');
            $table->char('software_key_fingerprint', 64)->nullable()->after('software_validation_number');
            $table->char('taxpayer_key_fingerprint', 64)->nullable()->after('software_key_fingerprint');

            $table->foreign(
                ['fiscal_series_id', 'workspace_id', 'legal_entity_id', 'establishment_id'],
                'fiscal_documents_series_tenant_fk',
            )->references(['id', 'workspace_id', 'legal_entity_id', 'establishment_id'])
                ->on('fiscal_series')
                ->restrictOnDelete();
            $table->foreign(
                ['agt_connection_id', 'workspace_id', 'legal_entity_id'],
                'fiscal_documents_connection_tenant_fk',
            )->references(['id', 'workspace_id', 'legal_entity_id'])
                ->on('agt_connections')
                ->restrictOnDelete();
            $table->foreign('issued_by_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->unique(
                ['fiscal_series_id', 'issue_sequence'],
                'fiscal_documents_series_sequence_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->dropForeign('fiscal_documents_series_tenant_fk');
            $table->dropForeign('fiscal_documents_connection_tenant_fk');
            $table->dropForeign(['issued_by_user_id']);
            $table->dropUnique('fiscal_documents_series_sequence_unique');
            $table->dropColumn([
                'fiscal_series_id',
                'agt_connection_id',
                'issued_by_user_id',
                'issue_sequence',
                'signable_payload_sha256',
                'document_payload_sha256',
                'document_jws',
                'software_product_id',
                'software_product_version',
                'software_validation_number',
                'software_key_fingerprint',
                'taxpayer_key_fingerprint',
            ]);
        });
    }
};
