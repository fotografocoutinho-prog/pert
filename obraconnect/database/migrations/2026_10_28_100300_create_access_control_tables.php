<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo global, sincronizado a partir de App\Access\PermissionRegistry.
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('module', 50)->index();
            $table->string('description');
            $table->timestamps();
        });

        // Perfis pertencem sempre a uma empresa (copiados dos modelos em código).
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->string('template_key', 60)->nullable();
            $table->boolean('is_locked')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
            // Alvo das chaves estrangeiras compostas (impede perfis de outra empresa).
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('role_id');
            // active | suspended
            $table->string('status', 20)->default('active');
            $table->string('job_title', 100)->nullable();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->foreign(['tenant_id', 'role_id'])
                ->references(['tenant_id', 'id'])->on('roles')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
