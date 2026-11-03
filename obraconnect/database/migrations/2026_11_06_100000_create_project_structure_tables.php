<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Edifício → piso → zona numa só hierarquia (App\Enums\LocationKind). As regras de
        // encadeamento (piso só dentro de edifício, etc.) são verificadas na aplicação.
        Schema::create('project_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('kind', 20);
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['project_id', 'parent_id']);
            $table->foreign(['tenant_id', 'project_id'])
                ->references(['tenant_id', 'id'])->on('projects')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'parent_id'])
                ->references(['tenant_id', 'id'])->on('project_locations')
                ->cascadeOnDelete();
        });

        Schema::create('construction_elements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('location_id');
            // App\Enums\ElementType
            $table->string('element_type', 20);
            $table->string('code', 30);
            $table->string('name', 150)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'project_id', 'code']);
            $table->index('location_id');
            $table->foreign(['tenant_id', 'project_id'])
                ->references(['tenant_id', 'id'])->on('projects')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'location_id'])
                ->references(['tenant_id', 'id'])->on('project_locations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_elements');
        Schema::dropIfExists('project_locations');
    }
};
