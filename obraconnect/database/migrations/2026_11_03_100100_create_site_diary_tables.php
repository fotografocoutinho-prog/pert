<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Um registo por obra e por dia. Depois de submetido não se altera.
        Schema::create('site_diary_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id');
            $table->date('entry_date');
            // App\Enums\DiaryEntryStatus
            $table->string('status', 20)->default('draft');
            // {"morning": "chuva", "afternoon": "nublado", "temp_min": 8, "temp_max": 15}
            $table->json('weather')->nullable();
            $table->text('work_summary')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'project_id', 'entry_date']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'project_id'])
                ->references(['tenant_id', 'id'])->on('projects')
                ->cascadeOnDelete();
        });

        // Mão de obra, equipamentos, materiais, ocorrências e visitas (App\Enums\DiaryItemKind).
        Schema::create('site_diary_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('entry_id');
            $table->string('kind', 20);
            $table->string('description');
            $table->decimal('quantity', 14, 3)->nullable();
            $table->string('unit', 20)->nullable();
            $table->string('company', 150)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['entry_id', 'kind']);
            $table->foreign(['tenant_id', 'entry_id'])
                ->references(['tenant_id', 'id'])->on('site_diary_entries')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_diary_items');
        Schema::dropIfExists('site_diary_entries');
    }
};
