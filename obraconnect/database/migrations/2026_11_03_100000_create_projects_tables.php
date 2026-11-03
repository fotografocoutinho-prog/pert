<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Alvo da chave estrangeira composta das equipas de obra.
        Schema::table('memberships', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('owner_name', 150)->nullable();
            // App\Enums\ProjectType
            $table->string('type', 30)->nullable();
            $table->string('address')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city', 100)->nullable();
            // Sem IVA, em cêntimos.
            $table->unsignedBigInteger('contract_value_cents')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('planned_end_on')->nullable();
            // App\Enums\ProjectStatus
            $table->string('status', 20)->default('planning');
            $table->text('description')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
        });

        // Equipa da obra: quem trabalha nela e com que perfil (pode ser diferente do perfil na empresa).
        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('membership_id');
            $table->unsignedBigInteger('role_id');
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'membership_id']);
            $table->index('membership_id');
            $table->foreign(['tenant_id', 'project_id'])
                ->references(['tenant_id', 'id'])->on('projects')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'membership_id'])
                ->references(['tenant_id', 'id'])->on('memberships')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'role_id'])
                ->references(['tenant_id', 'id'])->on('roles')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'id']);
        });
    }
};
