<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trabalhadores (da empresa ou de um subempreiteiro); podem ou não ter conta na aplicação.
        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            // Cifrado na base de dados (cast "encrypted").
            $table->text('nif')->nullable();
            $table->string('category', 100)->nullable();
            $table->bigInteger('hourly_cost_cents')->default(0);
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index('partner_id');
            $table->foreign(['tenant_id', 'partner_id'], 'workers_partner_fk')->references(['tenant_id', 'id'])->on('partners');
        });

        // Planeamento: atividades (com resumo/WBS) e precedências; datas calculadas pelo CPM.
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('code', 30);
            $table->string('name', 200);
            $table->unsignedInteger('duration_days')->default(1);
            // PERT (opcional): otimista, mais provável e pessimista, em dias úteis.
            $table->decimal('optimistic_days', 8, 2)->nullable();
            $table->decimal('likely_days', 8, 2)->nullable();
            $table->decimal('pessimistic_days', 8, 2)->nullable();
            $table->date('start_no_earlier_than')->nullable();
            $table->unsignedTinyInteger('progress_pct')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('element_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'project_id', 'code']);
            $table->index('parent_id');
            $table->foreign(['tenant_id', 'project_id'])->references(['tenant_id', 'id'])->on('projects')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'parent_id'], 'activities_parent_fk')->references(['tenant_id', 'id'])->on('activities')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'location_id'], 'activities_location_fk')->references(['tenant_id', 'id'])->on('project_locations');
            $table->foreign(['tenant_id', 'element_id'], 'activities_element_fk')->references(['tenant_id', 'id'])->on('construction_elements');
        });

        Schema::create('activity_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('predecessor_id');
            $table->unsignedBigInteger('successor_id');
            // FS, SS, FF, SF
            $table->string('type', 2)->default('FS');
            $table->integer('lag_days')->default(0);

            $table->unique(['predecessor_id', 'successor_id']);
            $table->index('successor_id');
            $table->foreign(['tenant_id', 'predecessor_id'])->references(['tenant_id', 'id'])->on('activities')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'successor_id'])->references(['tenant_id', 'id'])->on('activities')->cascadeOnDelete();
        });

        // A inspeção pode ser de uma atividade: com ponto de paragem por libertar, a atividade não se conclui.
        Schema::table('inspections', function (Blueprint $table) {
            $table->unsignedBigInteger('activity_id')->nullable();
            $table->index('activity_id');
            $table->foreign(['tenant_id', 'activity_id'], 'inspections_activity_fk')->references(['tenant_id', 'id'])->on('activities');
        });

        // Horas por trabalhador, obra e dia (com custo/hora copiado no momento).
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('worker_id');
            $table->unsignedBigInteger('activity_id')->nullable();
            $table->date('work_date');
            $table->decimal('hours', 5, 2);
            $table->decimal('overtime_hours', 5, 2)->default(0);
            $table->string('description', 255)->nullable();
            $table->bigInteger('hourly_cost_cents')->default(0);
            // App\Enums\TimeEntryStatus
            $table->string('status', 20)->default('pending');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['worker_id', 'project_id', 'work_date']);
            $table->index(['project_id', 'work_date']);
            $table->foreign(['tenant_id', 'project_id'])->references(['tenant_id', 'id'])->on('projects')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'worker_id'], 'time_entries_worker_fk')->references(['tenant_id', 'id'])->on('workers');
            $table->foreign(['tenant_id', 'activity_id'], 'time_entries_activity_fk')->references(['tenant_id', 'id'])->on('activities');
        });

        // Equipamentos da empresa, com etiqueta QR; utilização por obra e manutenções.
        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('category', 100)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->bigInteger('hourly_cost_cents')->default(0);
            // App\Enums\EquipmentStatus
            $table->string('status', 20)->default('available');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('qr_token', 40);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
            $table->unique('qr_token');
            $table->index('project_id');
            $table->foreign(['tenant_id', 'project_id'], 'equipment_project_fk')->references(['tenant_id', 'id'])->on('projects');
        });

        Schema::create('equipment_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('equipment_id');
            $table->unsignedBigInteger('project_id');
            $table->date('used_on');
            $table->decimal('hours', 6, 2);
            $table->bigInteger('hourly_cost_cents')->default(0);
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'used_on']);
            $table->index('equipment_id');
            $table->foreign(['tenant_id', 'equipment_id'])->references(['tenant_id', 'id'])->on('equipment')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'project_id'])->references(['tenant_id', 'id'])->on('projects')->cascadeOnDelete();
        });

        Schema::create('maintenance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('equipment_id');
            $table->date('performed_on');
            $table->string('description', 255);
            $table->bigInteger('cost_cents')->default(0);
            $table->date('next_due_on')->nullable();
            $table->timestamps();

            $table->index('equipment_id');
            $table->foreign(['tenant_id', 'equipment_id'])->references(['tenant_id', 'id'])->on('equipment')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['maintenance_records', 'equipment_usages', 'equipment', 'time_entries'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('inspections', function (Blueprint $table) {
            $table->dropForeign('inspections_activity_fk');
            $table->dropIndex(['activity_id']);
            $table->dropColumn('activity_id');
        });
        foreach (['activity_dependencies', 'activities', 'workers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
