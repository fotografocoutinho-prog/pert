<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pontos de paragem: os trabalhos não avançam até alguém com inspections.release_hold_point
        // libertar a inspeção (depois de aprovada). Uma lista pode trazer o ponto de paragem por omissão.
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->boolean('hold_point')->default(false);
        });
        Schema::table('inspections', function (Blueprint $table) {
            $table->boolean('hold_point')->default(false);
            $table->timestamp('hold_released_at')->nullable();
            $table->foreignId('hold_released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        // Ocorrências e não conformidades, com responsável, prazo e escalonamento.
        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id');
            $table->unsignedInteger('number');
            // App\Enums\IssueKind, IssueSeverity, IssueStatus
            $table->string('kind', 20);
            $table->string('severity', 20);
            $table->string('status', 20)->default('open');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('element_id')->nullable();
            $table->unsignedBigInteger('specialty_id')->nullable();
            $table->unsignedBigInteger('inspection_id')->nullable();
            $table->unsignedBigInteger('inspection_item_id')->nullable();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'project_id', 'number']);
            $table->index(['tenant_id', 'project_id', 'status']);
            $table->index(['status', 'due_on']);
            $table->index('location_id');
            $table->index('element_id');
            $table->index('specialty_id');
            $table->index('inspection_id');
            $table->index('inspection_item_id');
            $table->foreign(['tenant_id', 'project_id'])
                ->references(['tenant_id', 'id'])->on('projects')
                ->cascadeOnDelete();
            // Sem "set null" (poria tenant_id a nulo): com ocorrências, a aplicação recusa apagar.
            $table->foreign(['tenant_id', 'location_id'], 'issues_location_fk')
                ->references(['tenant_id', 'id'])->on('project_locations');
            $table->foreign(['tenant_id', 'element_id'], 'issues_element_fk')
                ->references(['tenant_id', 'id'])->on('construction_elements');
            $table->foreign(['tenant_id', 'specialty_id'], 'issues_specialty_fk')
                ->references(['tenant_id', 'id'])->on('specialties');
            $table->foreign(['tenant_id', 'inspection_id'], 'issues_inspection_fk')
                ->references(['tenant_id', 'id'])->on('inspections');
            $table->foreign(['tenant_id', 'inspection_item_id'], 'issues_inspection_item_fk')
                ->references(['tenant_id', 'id'])->on('inspection_items');
        });

        // Comentários, mudanças de estado, de responsável e de prazo, escalonamentos. Só se acrescenta.
        Schema::create('issue_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('issue_id');
            $table->string('type', 30);
            // Nulo nos escalonamentos automáticos. Nome copiado: a linha não depende do utilizador.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 150)->nullable();
            $table->text('body')->nullable();
            $table->text('payload')->nullable();
            $table->timestamp('created_at');

            $table->index('issue_id');
            $table->foreign(['tenant_id', 'issue_id'])
                ->references(['tenant_id', 'id'])->on('issues')
                ->cascadeOnDelete();
        });

        Schema::table('media', function (Blueprint $table) {
            $table->unsignedBigInteger('issue_id')->nullable();
            $table->index('issue_id');
            $table->foreign(['tenant_id', 'issue_id'], 'media_issue_fk')
                ->references(['tenant_id', 'id'])->on('issues')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropForeign('media_issue_fk');
            $table->dropIndex(['issue_id']);
            $table->dropColumn('issue_id');
        });
        Schema::dropIfExists('issue_events');
        Schema::dropIfExists('issues');

        Schema::table('inspections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hold_released_by_user_id');
            $table->dropColumn(['hold_point', 'hold_released_at']);
        });
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->dropColumn('hold_point');
        });
    }
};
