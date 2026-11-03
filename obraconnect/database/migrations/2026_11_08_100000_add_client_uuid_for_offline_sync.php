<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Identificador criado no telemóvel para cada registo e fotografia feitos sem rede.
     * Um reenvio (resposta perdida, sincronização repetida) devolve o que já existe.
     */
    public function up(): void
    {
        foreach (['site_diary_entries', 'media'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->uuid('client_uuid')->nullable();
                $table->unique(['tenant_id', 'client_uuid']);
            });
        }
    }

    public function down(): void
    {
        foreach (['site_diary_entries', 'media'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropUnique(['tenant_id', 'client_uuid']);
                $table->dropColumn('client_uuid');
            });
        }
    }
};
