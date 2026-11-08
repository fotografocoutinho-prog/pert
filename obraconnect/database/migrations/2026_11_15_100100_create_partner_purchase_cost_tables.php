<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fornecedores e subempreiteiros (entidades externas da empresa).
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // App\Enums\PartnerKind
            $table->string('kind', 20);
            $table->string('name', 200);
            $table->string('nif', 20)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('contact_name', 150)->nullable();
            $table->string('address', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'nif']);
        });

        Schema::create('partner_specialty', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('partner_id');
            $table->unsignedBigInteger('specialty_id');

            $table->unique(['partner_id', 'specialty_id']);
            $table->foreign(['tenant_id', 'partner_id'])->references(['tenant_id', 'id'])->on('partners')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'specialty_id'])->references(['tenant_id', 'id'])->on('specialties')->cascadeOnDelete();
        });

        // Documentos com validade (alvará, seguro, declarações de não dívida...): só os dados e a data.
        Schema::create('partner_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('partner_id');
            $table->string('title', 150);
            $table->string('reference', 100)->nullable();
            $table->date('expires_on')->nullable();
            $table->timestamps();

            $table->index('partner_id');
            $table->foreign(['tenant_id', 'partner_id'])->references(['tenant_id', 'id'])->on('partners')->cascadeOnDelete();
        });

        // Contratos de subempreitada (por obra).
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('partner_id');
            $table->string('code', 30);
            $table->string('title', 200);
            $table->decimal('retention_pct', 5, 2)->default(0);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            // App\Enums\ContractStatus
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'project_id', 'code']);
            $table->index('partner_id');
            $table->foreign(['tenant_id', 'project_id'])->references(['tenant_id', 'id'])->on('projects')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'partner_id'], 'contracts_partner_fk')->references(['tenant_id', 'id'])->on('partners');
        });

        Schema::create('contract_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('budget_item_id')->nullable();
            $table->string('description', 1000);
            $table->string('unit', 20);
            $table->decimal('quantity', 14, 3);
            $table->bigInteger('unit_price_cents');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index('contract_id');
            $table->index('budget_item_id');
            $table->foreign(['tenant_id', 'contract_id'])->references(['tenant_id', 'id'])->on('contracts')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'budget_item_id'], 'contract_items_budget_item_fk')->references(['tenant_id', 'id'])->on('budget_items');
        });

        // Autos de medição: ao dono de obra (contract_id nulo, sobre o orçamento) ou de um
        // subempreiteiro (sobre os artigos do contrato).
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('budget_id')->nullable();
            $table->unsignedBigInteger('contract_id')->nullable();
            // 'owner' ou 'contract:{id}': a numeração é por um ou pelo outro.
            $table->string('scope', 40);
            $table->unsignedInteger('number');
            $table->date('period_start');
            $table->date('period_end');
            // App\Enums\CertificateStatus
            $table->string('status', 20)->default('draft');
            $table->bigInteger('amount_cents')->default(0);
            $table->bigInteger('retention_cents')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'project_id', 'scope', 'number']);
            $table->index('budget_id');
            $table->index('contract_id');
            $table->foreign(['tenant_id', 'project_id'])->references(['tenant_id', 'id'])->on('projects')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'budget_id'], 'certificates_budget_fk')->references(['tenant_id', 'id'])->on('budgets');
            $table->foreign(['tenant_id', 'contract_id'], 'certificates_contract_fk')->references(['tenant_id', 'id'])->on('contracts');
        });

        Schema::create('certificate_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('certificate_id');
            $table->unsignedBigInteger('budget_item_id')->nullable();
            $table->unsignedBigInteger('contract_item_id')->nullable();
            $table->string('code', 30)->nullable();
            $table->string('description', 1000);
            $table->string('unit', 20);
            $table->bigInteger('unit_price_cents');
            $table->decimal('qty_contract', 14, 3)->default(0);
            $table->decimal('qty_previous', 14, 3)->default(0);
            $table->decimal('qty_period', 14, 3)->default(0);
            $table->bigInteger('amount_cents')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('certificate_id');
            $table->foreign(['tenant_id', 'certificate_id'])->references(['tenant_id', 'id'])->on('certificates')->cascadeOnDelete();
        });

        Schema::table('measurements', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'certificate_id'], 'measurements_certificate_fk')->references(['tenant_id', 'id'])->on('certificates');
        });

        // Compras (encomendas a fornecedores): aprovada = custo comprometido; faturada = custo realizado.
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('partner_id');
            $table->unsignedInteger('number');
            // App\Enums\PurchaseStatus
            $table->string('status', 20)->default('draft');
            // App\Enums\CostCategory
            $table->string('category', 20)->default('material');
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->date('ordered_on')->nullable();
            $table->date('expected_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('invoice_number', 60)->nullable();
            $table->date('invoiced_on')->nullable();
            $table->bigInteger('invoiced_cents')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'project_id', 'number']);
            $table->index('partner_id');
            $table->index('cost_center_id');
            $table->foreign(['tenant_id', 'project_id'])->references(['tenant_id', 'id'])->on('projects')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'partner_id'], 'purchase_orders_partner_fk')->references(['tenant_id', 'id'])->on('partners');
            $table->foreign(['tenant_id', 'cost_center_id'], 'purchase_orders_cost_center_fk')->references(['tenant_id', 'id'])->on('cost_centers');
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->unsignedBigInteger('budget_item_id')->nullable();
            $table->string('description', 500);
            $table->string('unit', 20);
            $table->decimal('quantity', 14, 3);
            $table->bigInteger('unit_price_cents');
            $table->decimal('received_quantity', 14, 3)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('purchase_order_id');
            $table->foreign(['tenant_id', 'purchase_order_id'])->references(['tenant_id', 'id'])->on('purchase_orders')->cascadeOnDelete();
        });

        // Custos lançados à mão (o resto vem das compras, horas, equipamentos e autos de subempreitada).
        Schema::create('cost_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->string('category', 20);
            // committed (comprometido) ou actual (realizado)
            $table->string('kind', 20);
            $table->string('description', 255);
            $table->bigInteger('amount_cents');
            $table->date('incurred_on');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'incurred_on']);
            $table->index('cost_center_id');
            $table->foreign(['tenant_id', 'project_id'])->references(['tenant_id', 'id'])->on('projects')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'cost_center_id'], 'cost_entries_cost_center_fk')->references(['tenant_id', 'id'])->on('cost_centers');
        });

        // Teto de custo da obra (vazio = o custo do orçamento aprovado).
        Schema::table('projects', function (Blueprint $table) {
            $table->bigInteger('cost_ceiling_cents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('cost_ceiling_cents');
        });
        Schema::table('measurements', function (Blueprint $table) {
            $table->dropForeign('measurements_certificate_fk');
        });
        foreach (['cost_entries', 'purchase_order_lines', 'purchase_orders', 'certificate_lines', 'certificates', 'contract_items', 'contracts', 'partner_documents', 'partner_specialty', 'partners'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
