<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Definições da plataforma editáveis no backoffice (sem empresa). A primeira é
        // "billing.payment": os dados para as empresas pagarem (titular, IBAN, indicações).
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->json('value');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
