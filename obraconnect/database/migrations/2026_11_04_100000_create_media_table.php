<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fotografias da obra. O original nunca é alterado (sha256 registado); a pré-visualização
        // e a miniatura são geradas em fila. Cada módulo com anexos acrescenta aqui a sua coluna
        // de dono com FK composta (em vez de uma relação polimórfica sem integridade).
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('diary_entry_id')->nullable();
            $table->string('kind', 20)->default('photo');
            // App\Enums\MediaStatus
            $table->string('status', 20)->default('processing');
            $table->string('disk', 30);
            $table->string('original_path');
            $table->string('preview_path')->nullable();
            $table->string('thumb_path')->nullable();
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedBigInteger('derived_bytes')->default(0);
            $table->char('sha256', 64);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamp('taken_at')->nullable();
            $table->string('caption')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'project_id']);
            $table->index('diary_entry_id');
            $table->foreign(['tenant_id', 'project_id'])
                ->references(['tenant_id', 'id'])->on('projects')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'diary_entry_id'])
                ->references(['tenant_id', 'id'])->on('site_diary_entries')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
