<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uma linha por tarefa agendada (cron): quando correu, se correu bem e o último erro. É o que diz
        // se o cron está vivo e se os emails, alertas e leituras estão a sair. Sem empresa (é da plataforma).
        Schema::create('system_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            // running | ok | failed
            $table->string('last_status', 10)->nullable();
            $table->unsignedInteger('last_duration_ms')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedBigInteger('runs')->default(0);
            $table->timestamps();
        });

        // Estado de cada verificação (para avisar uma vez quando falha e outra quando volta ao normal).
        Schema::create('system_checks', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            // ok | warning | failing
            $table->string('status', 10);
            $table->text('detail')->nullable();
            $table->timestamp('since');
            $table->timestamp('checked_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_checks');
        Schema::dropIfExists('system_tasks');
    }
};
