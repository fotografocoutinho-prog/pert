<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Modelos de listas de verificação da empresa. Mudar os pontos sobe a versão; as inspeções
        // guardam uma cópia dos pontos, por isso nunca mudam por baixo de quem as fez.
        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('specialty_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'name']);
            $table->index('specialty_id');
            $table->foreign(['tenant_id', 'specialty_id'], 'checklist_templates_specialty_fk')
                ->references(['tenant_id', 'id'])->on('specialties');
        });

        Schema::create('checklist_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('template_id');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('text', 500);
            $table->string('guidance', 500)->nullable();
            $table->boolean('requires_photo')->default(false);
            $table->unsignedTinyInteger('min_photos')->default(0);
            $table->boolean('requires_measure')->default(false);
            $table->string('measure_unit', 20)->nullable();
            $table->decimal('measure_min', 14, 4)->nullable();
            $table->decimal('measure_max', 14, 4)->nullable();
            $table->timestamps();

            $table->index('template_id');
            $table->foreign(['tenant_id', 'template_id'])
                ->references(['tenant_id', 'id'])->on('checklist_templates')
                ->cascadeOnDelete();
        });

        // Inspeções da obra. App\Enums\InspectionStatus tem os 9 estados e as passagens permitidas.
        Schema::create('inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id');
            $table->unsignedInteger('number');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('checklist_template_id')->nullable();
            $table->unsignedInteger('template_version')->nullable();
            $table->unsignedBigInteger('reinspection_of_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('element_id')->nullable();
            $table->unsignedBigInteger('specialty_id')->nullable();
            $table->string('status', 30)->default('draft');
            $table->boolean('remote')->default(false);
            $table->dateTime('scheduled_for')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('inspector_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            // Índice de suficiência das evidências no momento da submissão (0-100).
            $table->unsignedTinyInteger('evidence_score')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'project_id', 'number']);
            $table->index(['tenant_id', 'project_id', 'status']);
            $table->index('checklist_template_id');
            $table->index('reinspection_of_id');
            $table->index('location_id');
            $table->index('element_id');
            $table->index('specialty_id');
            $table->foreign(['tenant_id', 'project_id'])
                ->references(['tenant_id', 'id'])->on('projects')
                ->cascadeOnDelete();
            // Sem "set null" (poria tenant_id a nulo): com inspeções, a aplicação recusa apagar.
            $table->foreign(['tenant_id', 'checklist_template_id'], 'inspections_template_fk')
                ->references(['tenant_id', 'id'])->on('checklist_templates');
            $table->foreign(['tenant_id', 'reinspection_of_id'], 'inspections_reinspection_fk')
                ->references(['tenant_id', 'id'])->on('inspections');
            $table->foreign(['tenant_id', 'location_id'], 'inspections_location_fk')
                ->references(['tenant_id', 'id'])->on('project_locations');
            $table->foreign(['tenant_id', 'element_id'], 'inspections_element_fk')
                ->references(['tenant_id', 'id'])->on('construction_elements');
            $table->foreign(['tenant_id', 'specialty_id'], 'inspections_specialty_fk')
                ->references(['tenant_id', 'id'])->on('specialties');
        });

        // Pontos a verificar: cópia dos do modelo (ou escritos na própria inspeção) e o resultado.
        Schema::create('inspection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('inspection_id');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('text', 500);
            $table->string('guidance', 500)->nullable();
            $table->boolean('requires_photo')->default(false);
            $table->unsignedTinyInteger('min_photos')->default(0);
            $table->boolean('requires_measure')->default(false);
            $table->string('measure_unit', 20)->nullable();
            $table->decimal('measure_min', 14, 4)->nullable();
            $table->decimal('measure_max', 14, 4)->nullable();
            // App\Enums\InspectionResult (ok / nok / na); nulo = por verificar.
            $table->string('result', 10)->nullable();
            $table->decimal('measure_value', 14, 4)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('answered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index('inspection_id');
            $table->foreign(['tenant_id', 'inspection_id'])
                ->references(['tenant_id', 'id'])->on('inspections')
                ->cascadeOnDelete();
        });

        // Histórico imutável: cada passagem de estado acrescenta uma linha, encadeada pelo hash da
        // anterior. Sem FK para users e com o nome copiado: nada fora da linha pode alterá-la.
        Schema::create('inspection_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('inspection_id');
            $table->unsignedInteger('sequence');
            $table->string('action', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 150);
            $table->text('reason')->nullable();
            // JSON canónico, guardado como texto: é exatamente o que entrou no hash.
            $table->longText('payload')->nullable();
            $table->char('prev_hash', 64);
            $table->char('hash', 64);
            $table->timestamp('created_at');

            $table->unique(['inspection_id', 'sequence']);
            $table->foreign(['tenant_id', 'inspection_id'])
                ->references(['tenant_id', 'id'])->on('inspections')
                ->cascadeOnDelete();
        });

        // Fotografias de evidência: mesma tabela das do diário, com o ponto da inspeção como dono.
        Schema::table('media', function (Blueprint $table) {
            $table->unsignedBigInteger('inspection_item_id')->nullable();
            $table->index('inspection_item_id');
            $table->foreign(['tenant_id', 'inspection_item_id'], 'media_inspection_item_fk')
                ->references(['tenant_id', 'id'])->on('inspection_items')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropForeign('media_inspection_item_fk');
            $table->dropIndex(['inspection_item_id']);
            $table->dropColumn('inspection_item_id');
        });

        Schema::dropIfExists('inspection_events');
        Schema::dropIfExists('inspection_items');
        Schema::dropIfExists('inspections');
        Schema::dropIfExists('checklist_template_items');
        Schema::dropIfExists('checklist_templates');
    }
};
