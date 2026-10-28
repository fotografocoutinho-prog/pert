<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('legal_name', 200)->nullable();
            $table->string('nif', 20)->nullable()->index();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('website')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city', 100)->nullable();
            $table->char('country_code', 2)->default('PT');
            $table->string('logo_path')->nullable();
            // active | read_only | suspended
            $table->string('status', 20)->default('active')->index();
            // admin | subscription_expired | ...
            $table->string('status_reason', 255)->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->json('settings')->nullable();
            $table->unsignedBigInteger('storage_used_bytes')->default(0);
            // Nulo = base partilhada. Preparado para bases dedicadas (Enterprise).
            $table->string('database_connection', 64)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deletion_requested_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('current_tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_tenant_id']);
        });
        Schema::dropIfExists('tenants');
    }
};
