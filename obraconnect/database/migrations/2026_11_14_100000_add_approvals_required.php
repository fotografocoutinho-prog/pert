<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Aprovações em vários passos: quantas pessoas diferentes têm de aprovar (1 a 3). */
    private const TABLES = ['checklist_templates', 'inspections'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedTinyInteger('approvals_required')->default(1);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('approvals_required');
            });
        }
    }
};
