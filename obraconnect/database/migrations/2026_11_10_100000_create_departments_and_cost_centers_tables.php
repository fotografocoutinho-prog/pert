<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'name']);
        });

        // Cada pessoa pertence a um departamento (ou a nenhum). Sem "set null" (poria tenant_id a
        // nulo): ao apagar um departamento, a aplicação tira-lhe primeiro as pessoas.
        Schema::table('memberships', function (Blueprint $table) {
            $table->unsignedBigInteger('department_id')->nullable()->after('role_id');
            $table->index('department_id');
            $table->foreign(['tenant_id', 'department_id'], 'memberships_department_fk')
                ->references(['tenant_id', 'id'])->on('departments');
        });

        // Centros de custo em árvore (ex.: 1 Obras → 1.01 Moradia; 2 Estrutura → 2.01 Armazém).
        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
            $table->index('parent_id');
            // A aplicação só apaga centros sem filhos e sem obras (COST_CENTER_IN_USE).
            $table->foreign(['tenant_id', 'parent_id'])
                ->references(['tenant_id', 'id'])->on('cost_centers');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('cost_center_id')->nullable()->after('work_calendar_id');
            $table->index('cost_center_id');
            $table->foreign(['tenant_id', 'cost_center_id'], 'projects_cost_center_fk')
                ->references(['tenant_id', 'id'])->on('cost_centers');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign('projects_cost_center_fk');
            $table->dropIndex(['cost_center_id']);
            $table->dropColumn('cost_center_id');
        });

        Schema::dropIfExists('cost_centers');

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropForeign('memberships_department_fk');
            $table->dropIndex(['department_id']);
            $table->dropColumn('department_id');
        });

        Schema::dropIfExists('departments');
    }
};
