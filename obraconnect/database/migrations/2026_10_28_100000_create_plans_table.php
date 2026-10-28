<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            // Preços sem IVA, em cêntimos. Nulo = preço sob consulta.
            $table->unsignedInteger('price_monthly_cents')->nullable();
            $table->unsignedInteger('price_yearly_cents')->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->unsignedSmallInteger('trial_days')->default(0);
            // Ex.: {"max_users": 10, "max_active_projects": 3, "storage_mb": 5120}. Nulo num limite = ilimitado.
            $table->json('limits');
            // Ex.: ["inspections", "costs", "ai"]
            $table->json('features');
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
