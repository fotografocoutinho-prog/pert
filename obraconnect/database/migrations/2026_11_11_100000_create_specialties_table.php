<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Elementos construtivos e documentos podem dizer de que especialidade são. */
    private const LINKED = ['construction_elements', 'documents'];

    public function up(): void
    {
        // Especialidades de projeto e obra (Arquitetura, Estruturas, AVAC...), por empresa.
        Schema::create('specialties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 10);
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
        });

        foreach (self::LINKED as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->unsignedBigInteger('specialty_id')->nullable();
                $table->index('specialty_id');
                // Sem "set null" (poria tenant_id a nulo): com ligações, a aplicação recusa apagar (SPECIALTY_IN_USE).
                $table->foreign(['tenant_id', 'specialty_id'], "{$name}_specialty_fk")
                    ->references(['tenant_id', 'id'])->on('specialties');
            });
        }
    }

    public function down(): void
    {
        foreach (self::LINKED as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropForeign("{$name}_specialty_fk");
                $table->dropIndex(['specialty_id']);
                $table->dropColumn('specialty_id');
            });
        }

        Schema::dropIfExists('specialties');
    }
};
