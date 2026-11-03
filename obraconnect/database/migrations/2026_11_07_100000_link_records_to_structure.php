<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Linhas do diário, fotografias e documentos podem dizer a que local ou elemento se referem. */
    private const TABLES = ['site_diary_items', 'media', 'documents'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->unsignedBigInteger('location_id')->nullable();
                $table->unsignedBigInteger('element_id')->nullable();

                $table->index('location_id');
                $table->index('element_id');
                // Sem "set null": numa FK composta poria tenant_id a nulo. A aplicação
                // recusa apagar locais e elementos com ligações (LOCATION_IN_USE / ELEMENT_IN_USE).
                $table->foreign(['tenant_id', 'location_id'], "{$name}_location_fk")
                    ->references(['tenant_id', 'id'])->on('project_locations');
                $table->foreign(['tenant_id', 'element_id'], "{$name}_element_fk")
                    ->references(['tenant_id', 'id'])->on('construction_elements');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropForeign("{$name}_location_fk");
                $table->dropForeign("{$name}_element_fk");
                $table->dropIndex(['location_id']);
                $table->dropIndex(['element_id']);
                $table->dropColumn(['location_id', 'element_id']);
            });
        }
    }
};
