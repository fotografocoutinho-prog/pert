<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pessoas de um subempreiteiro com acesso ao portal: veem os contratos desse subempreiteiro com a
        // empresa, propõem autos e enviam documentos. Não são membros da empresa. Convite por email
        // (token guardado só como hash); revogado = deixa de entrar.
        Schema::create('partner_portal_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('partner_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email', 190);
            $table->string('name', 150);
            $table->char('token_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['partner_id', 'email']);
            $table->index('user_id');
            $table->foreign(['tenant_id', 'partner_id'], 'partner_portal_users_partner_fk')->references(['tenant_id', 'id'])->on('partners')->cascadeOnDelete();
        });

        // Os documentos com validade passam a poder ter o ficheiro (enviado pela empresa ou pelo portal).
        Schema::table('partner_documents', function (Blueprint $table) {
            $table->string('disk', 40)->nullable();
            $table->string('path', 500)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedBigInteger('uploaded_by_portal_user_id')->nullable()->index();
        });

        // Auto proposto pelo subempreiteiro no portal (a empresa aprova ou devolve como sempre).
        Schema::table('certificates', function (Blueprint $table) {
            $table->unsignedBigInteger('proposed_by_portal_user_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropIndex(['proposed_by_portal_user_id']);
            $table->dropColumn('proposed_by_portal_user_id');
        });
        Schema::table('partner_documents', function (Blueprint $table) {
            $table->dropIndex(['uploaded_by_portal_user_id']);
            $table->dropColumn(['disk', 'path', 'original_name', 'mime', 'size_bytes', 'uploaded_by_portal_user_id']);
        });
        Schema::dropIfExists('partner_portal_users');
    }
};
