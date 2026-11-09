<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Faturas, notas de crédito, talões e guias de fornecedores: o ficheiro, o que foi lido (pelo
        // DocIntel ou à mão) e onde foi lançado (compra da obra ou custo).
        Schema::create('supplier_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->unsignedBigInteger('cost_entry_id')->nullable();
            $table->unsignedBigInteger('cost_center_id')->nullable();
            // App\Enums\SupplierDocumentKind e App\Enums\SupplierDocumentStatus
            $table->string('kind', 20)->default('invoice');
            $table->string('status', 20)->default('to_review');
            // App\Enums\CostCategory (quando é lançada como custo)
            $table->string('category', 20)->default('material');

            // Ficheiro original (fora do public_html, como os documentos da obra).
            $table->string('disk', 40);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);

            // Leitura automática (DocIntel): null = à mão.
            $table->string('reader', 20)->nullable();
            $table->string('reader_document_id', 64)->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->text('review_reasons')->nullable();
            $table->text('read_error')->nullable();
            $table->timestamp('read_at')->nullable();
            // Linhas tal como foram lidas (só para consulta; o lançamento usa os totais).
            $table->longText('lines')->nullable();

            // Dados do documento (lidos e corrigidos por quem revê).
            $table->string('supplier_name', 200)->nullable();
            $table->string('supplier_nif', 20)->nullable();
            $table->string('customer_nif', 20)->nullable();
            $table->string('number', 60)->nullable();
            $table->string('atcud', 100)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('order_reference', 60)->nullable();
            $table->bigInteger('net_cents')->nullable();
            $table->bigInteger('vat_cents')->nullable();
            $table->bigInteger('gross_cents')->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            // O mesmo ficheiro não entra duas vezes na mesma empresa.
            $table->unique(['tenant_id', 'sha256']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'supplier_nif', 'number']);
            $table->index('project_id');
            $table->index('partner_id');
            $table->index('purchase_order_id');
            $table->index('cost_entry_id');
            $table->index('cost_center_id');
            $table->foreign(['tenant_id', 'project_id'], 'supplier_documents_project_fk')->references(['tenant_id', 'id'])->on('projects');
            $table->foreign(['tenant_id', 'partner_id'], 'supplier_documents_partner_fk')->references(['tenant_id', 'id'])->on('partners');
            $table->foreign(['tenant_id', 'purchase_order_id'], 'supplier_documents_purchase_fk')->references(['tenant_id', 'id'])->on('purchase_orders');
            $table->foreign(['tenant_id', 'cost_center_id'], 'supplier_documents_cost_center_fk')->references(['tenant_id', 'id'])->on('cost_centers');
        });

        // cost_entries não tinha a chave (tenant_id, id) para a FK composta.
        Schema::table('cost_entries', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id']);
        });
        Schema::table('supplier_documents', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'cost_entry_id'], 'supplier_documents_cost_entry_fk')->references(['tenant_id', 'id'])->on('cost_entries');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_documents');
        Schema::table('cost_entries', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'id']);
        });
    }
};
