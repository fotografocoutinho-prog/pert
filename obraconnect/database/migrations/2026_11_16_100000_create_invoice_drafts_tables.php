<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pré-faturas: documentos internos, sem valor fiscal, preparados aqui (por exemplo a partir de um auto
 * aprovado) e exportados para o programa de faturação certificado, que emite a fatura oficial. O número
 * e a data da fatura oficial ficam registados para fechar o ciclo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('certificate_id')->nullable();
            // Numeração interna (PF 2026/0001), dada quando fica pronta a faturar; não é numeração fiscal.
            $table->unsignedSmallInteger('series_year')->nullable();
            $table->unsignedInteger('number')->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            // Cópia dos dados do cliente no momento (mudar o cliente depois não altera a pré-fatura).
            $table->string('client_name', 200);
            $table->string('client_nif', 20)->nullable();
            $table->string('client_address', 255)->nullable();
            $table->string('client_postal_code', 20)->nullable();
            $table->string('client_city', 100)->nullable();
            $table->char('client_country', 2)->default('PT');
            $table->string('client_email', 190)->nullable();
            $table->string('reference', 150)->nullable();
            $table->string('vat_exemption_code', 10)->nullable();
            $table->string('vat_exemption_reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->string('official_number', 60)->nullable();
            $table->date('official_date')->nullable();
            $table->timestamp('invoiced_at')->nullable();
            $table->foreignId('invoiced_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'series_year', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index('project_id');
            $table->index('client_id');
            $table->index('certificate_id');
            $table->foreign(['tenant_id', 'project_id'])->references(['tenant_id', 'id'])->on('projects');
            $table->foreign(['tenant_id', 'client_id'])->references(['tenant_id', 'id'])->on('clients');
            $table->foreign(['tenant_id', 'certificate_id'])->references(['tenant_id', 'id'])->on('certificates');
        });

        Schema::create('invoice_draft_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('invoice_draft_id');
            $table->string('code', 30)->nullable();
            $table->string('description', 1000);
            $table->string('unit', 20)->default('un');
            $table->decimal('quantity', 14, 3);
            $table->bigInteger('unit_price_cents');
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(23);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('invoice_draft_id');
            $table->foreign(['tenant_id', 'invoice_draft_id'])->references(['tenant_id', 'id'])->on('invoice_drafts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_draft_lines');
        Schema::dropIfExists('invoice_drafts');
    }
};
