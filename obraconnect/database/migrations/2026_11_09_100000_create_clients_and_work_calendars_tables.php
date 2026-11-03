<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clientes da empresa (quem encomenda a obra). Dados pessoais no caso de particulares.
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // App\Enums\ClientType
            $table->string('type', 20);
            $table->string('name', 150);
            $table->string('nif', 20)->nullable();
            $table->char('country_code', 2)->default('PT');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('contact_name', 150)->nullable();
            $table->string('address')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'nif']);
            $table->index(['tenant_id', 'name']);
        });

        // Calendários de trabalho: horas por dia da semana (ISO 1 = segunda) e dias sem trabalho.
        Schema::create('work_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->json('weekly_hours');
            // Um por empresa (garantido pela aplicação): o das obras sem calendário escolhido.
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('work_calendar_id');
            $table->date('date');
            $table->string('name', 100);
            // App\Enums\HolidayKind
            $table->string('kind', 20);
            $table->timestamps();

            $table->unique(['work_calendar_id', 'date']);
            $table->foreign(['tenant_id', 'work_calendar_id'])
                ->references(['tenant_id', 'id'])->on('work_calendars')
                ->cascadeOnDelete();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable()->after('name');
            $table->unsignedBigInteger('work_calendar_id')->nullable()->after('planned_end_on');

            $table->index('client_id');
            $table->index('work_calendar_id');
            // Sem "set null" (poria tenant_id a nulo): a aplicação recusa apagar clientes e
            // calendários com obras (CLIENT_IN_USE / CALENDAR_IN_USE).
            $table->foreign(['tenant_id', 'client_id'], 'projects_client_fk')
                ->references(['tenant_id', 'id'])->on('clients');
            $table->foreign(['tenant_id', 'work_calendar_id'], 'projects_work_calendar_fk')
                ->references(['tenant_id', 'id'])->on('work_calendars');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign('projects_client_fk');
            $table->dropForeign('projects_work_calendar_fk');
            $table->dropIndex(['client_id']);
            $table->dropIndex(['work_calendar_id']);
            $table->dropColumn(['client_id', 'work_calendar_id']);
        });

        Schema::dropIfExists('holidays');
        Schema::dropIfExists('work_calendars');
        Schema::dropIfExists('clients');
    }
};
