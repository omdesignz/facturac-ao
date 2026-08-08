<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orçamentos — a proposal, not a fiscal document.
 *
 * Deliberately its own table rather than another fiscal document type: a quote
 * carries no series, is never communicated to the AGT, and stays editable while
 * the customer thinks about it. Putting it in fiscal_documents would mean every
 * receivables query, every AGT submission path and every immutability guard had
 * to learn to make an exception for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            /** Sequential per year and quotable to the customer: ORC 2026/0007. */
            $table->string('reference')->unique();
            $table->string('status')->index();

            /** Copied at creation so the quote still reads correctly later. */
            $table->string('customer_name');
            $table->string('customer_tax_identification_number')->nullable();
            $table->string('customer_country_code', 2)->default('AO');
            $table->string('customer_address')->nullable();

            $table->date('issue_date');
            $table->date('valid_until');
            $table->string('currency_code', 3);
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('net_total_minor')->default(0);
            $table->unsignedBigInteger('tax_total_minor')->default(0);
            $table->unsignedBigInteger('gross_total_minor')->default(0);

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();

            /** The invoice this quote turned into, once it did. */
            $table->foreignId('converted_document_id')->nullable()
                ->constrained('fiscal_documents')->nullOnDelete();

            $table->timestamps();

            $table->index(['legal_entity_id', 'status', 'issue_date']);
            $table->index(['customer_id', 'issue_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
