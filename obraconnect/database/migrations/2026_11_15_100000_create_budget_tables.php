<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Biblioteca de preços da empresa: recursos (materiais, mão de obra, equipamentos, subempreitadas)
        // e artigos, simples (preço próprio) ou compostos (soma de recursos × rendimento).
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // App\Enums\ResourceKind
            $table->string('kind', 20);
            $table->string('code', 30);
            $table->string('name', 200);
            $table->string('unit', 20);
            $table->bigInteger('unit_cost_cents')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('description', 1000);
            $table->string('unit', 20);
            // Preço de custo quando o artigo não tem composição.
            $table->bigInteger('unit_cost_cents')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('article_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('resource_id');
            // Rendimento: quantidade do recurso por unidade do artigo.
            $table->decimal('quantity', 14, 4);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('article_id');
            $table->index('resource_id');
            $table->foreign(['tenant_id', 'article_id'])->references(['tenant_id', 'id'])->on('articles')->cascadeOnDelete();
            // Sem cascata: um recurso usado em artigos arquiva-se em vez de se apagar.
            $table->foreign(['tenant_id', 'resource_id'])->references(['tenant_id', 'id'])->on('resources');
        });

        // Orçamentos da obra, com versões: o aprovado é a base do contrato e do custo previsto.
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id');
            $table->string('code', 30);
            $table->string('name', 200);
            $table->unsignedInteger('version')->default(1);
            // App\Enums\BudgetStatus
            $table->string('status', 20)->default('draft');
            // App\Enums\VatMode: normal, autoliquidação (subempreitadas), isento
            $table->string('vat_mode', 20)->default('normal');
            $table->decimal('vat_rate', 5, 2)->default(23);
            $table->decimal('overhead_pct', 6, 2)->default(0);
            $table->decimal('profit_pct', 6, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('revision_of_id')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'project_id', 'code', 'version']);
            $table->index('revision_of_id');
            $table->foreign(['tenant_id', 'project_id'])->references(['tenant_id', 'id'])->on('projects')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'revision_of_id'], 'budgets_revision_fk')->references(['tenant_id', 'id'])->on('budgets');
        });

        Schema::create('budget_chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('budget_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('code', 30);
            $table->string('name', 200);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index('budget_id');
            $table->index('parent_id');
            $table->foreign(['tenant_id', 'budget_id'])->references(['tenant_id', 'id'])->on('budgets')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'parent_id'], 'budget_chapters_parent_fk')->references(['tenant_id', 'id'])->on('budget_chapters')->cascadeOnDelete();
        });

        Schema::create('budget_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('budget_id');
            $table->unsignedBigInteger('chapter_id');
            $table->unsignedBigInteger('article_id')->nullable();
            $table->unsignedBigInteger('specialty_id')->nullable();
            $table->string('code', 30);
            $table->string('description', 1000);
            $table->string('unit', 20);
            $table->decimal('quantity', 14, 3)->default(0);
            // Custo unitário (da composição ou escrito); o preço de venda sai dos encargos e margem,
            // a não ser que se escreva um preço próprio.
            $table->bigInteger('unit_cost_cents')->default(0);
            $table->bigInteger('unit_price_cents')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index('budget_id');
            $table->index('chapter_id');
            $table->index('article_id');
            $table->index('specialty_id');
            $table->foreign(['tenant_id', 'budget_id'])->references(['tenant_id', 'id'])->on('budgets')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'chapter_id'])->references(['tenant_id', 'id'])->on('budget_chapters')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'article_id'], 'budget_items_article_fk')->references(['tenant_id', 'id'])->on('articles');
            $table->foreign(['tenant_id', 'specialty_id'], 'budget_items_specialty_fk')->references(['tenant_id', 'id'])->on('specialties');
        });

        // Composição do preço de cada artigo do orçamento (cópia, para o orçamento não mudar com a biblioteca).
        Schema::create('budget_item_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('budget_item_id');
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('kind', 20);
            $table->string('name', 200);
            $table->string('unit', 20);
            $table->decimal('quantity', 14, 4);
            $table->bigInteger('unit_cost_cents');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('budget_item_id');
            $table->foreign(['tenant_id', 'budget_item_id'])->references(['tenant_id', 'id'])->on('budget_items')->cascadeOnDelete();
        });

        // Medições do executado, por artigo do orçamento (e onde).
        Schema::create('measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('budget_item_id');
            $table->date('measured_on');
            $table->string('description', 500)->nullable();
            // Dimensões opcionais: quantidade = n.º × comprimento × largura × altura (as que existirem).
            $table->decimal('count', 14, 3)->nullable();
            $table->decimal('length', 14, 3)->nullable();
            $table->decimal('width', 14, 3)->nullable();
            $table->decimal('height', 14, 3)->nullable();
            $table->decimal('quantity', 14, 3);
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('element_id')->nullable();
            $table->unsignedBigInteger('certificate_id')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['project_id', 'measured_on']);
            $table->index('budget_item_id');
            $table->index('location_id');
            $table->index('element_id');
            $table->index('certificate_id');
            $table->foreign(['tenant_id', 'project_id'])->references(['tenant_id', 'id'])->on('projects')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'budget_item_id'], 'measurements_item_fk')->references(['tenant_id', 'id'])->on('budget_items');
            $table->foreign(['tenant_id', 'location_id'], 'measurements_location_fk')->references(['tenant_id', 'id'])->on('project_locations');
            $table->foreign(['tenant_id', 'element_id'], 'measurements_element_fk')->references(['tenant_id', 'id'])->on('construction_elements');
        });
    }

    public function down(): void
    {
        foreach (['measurements', 'budget_item_components', 'budget_items', 'budget_chapters', 'budgets', 'article_components', 'articles', 'resources'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
