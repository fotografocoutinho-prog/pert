<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fotografia semanal das previsões de cada obra: uma linha por obra e por semana (segunda-feira),
        // atualizada todos os dias até a semana acabar. É o histórico do separador Previsões.
        Schema::create('forecast_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id');
            $table->date('week_start');
            $table->date('taken_on');

            $table->boolean('cost_available')->default(false);
            $table->bigInteger('bac_cents')->nullable();
            $table->bigInteger('forecast_cents')->nullable();
            $table->bigInteger('ceiling_cents')->nullable();
            $table->bigInteger('actual_cents')->nullable();
            $table->bigInteger('committed_cents')->nullable();
            $table->bigInteger('sale_cents')->nullable();
            $table->decimal('cost_progress_pct', 5, 1)->nullable();
            $table->decimal('margin_pct', 7, 2)->nullable();
            $table->string('method', 10)->nullable();
            $table->string('confidence', 10)->nullable();
            $table->string('cost_alert', 10)->nullable();

            $table->boolean('schedule_available')->default(false);
            $table->string('schedule_status', 12)->nullable();
            $table->date('forecast_finish_on')->nullable();
            $table->date('planned_finish_on')->nullable();
            $table->date('contract_end_on')->nullable();
            $table->integer('delay_plan_days')->nullable();
            $table->integer('delay_contract_days')->nullable();
            $table->decimal('schedule_progress_pct', 5, 1)->nullable();
            $table->decimal('planned_progress_pct', 5, 1)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['project_id', 'week_start']);
            $table->index(['tenant_id', 'week_start']);
            $table->foreign(['tenant_id', 'project_id'], 'forecast_snapshots_project_fk')->references(['tenant_id', 'id'])->on('projects');
        });

        // Alertas automáticos (custo, prazo, medições, pontos de paragem, faturas por rever). Abrem e
        // fecham sozinhos conforme a condição; open_key só existe enquanto estão abertos, para não haver
        // dois iguais abertos ao mesmo tempo. project_id vazio = alerta da empresa.
        Schema::create('project_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id')->nullable();
            // App\Alerts\AlertType
            $table->string('type', 30);
            $table->string('level', 10);
            $table->string('title', 200);
            $table->text('detail');
            $table->string('tab', 30)->nullable();
            $table->text('data')->nullable();
            $table->string('open_key', 80)->nullable()->unique();
            $table->timestamp('opened_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'resolved_at']);
            $table->index('project_id');
            $table->foreign(['tenant_id', 'project_id'], 'project_alerts_project_fk')->references(['tenant_id', 'id'])->on('projects');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_alerts');
        Schema::dropIfExists('forecast_snapshots');
    }
};
