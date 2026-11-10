<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cupões da plataforma: desconto (percentagem ou valor) num pedido de ativação/renovação, ou dias
        // extra de período experimental. Cada empresa usa cada cupão uma vez.
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('description', 200)->nullable();
            $table->unsignedTinyInteger('percent_off')->nullable();
            $table->unsignedInteger('amount_off_cents')->nullable();
            $table->unsignedSmallInteger('trial_extension_days')->nullable();
            $table->text('plan_ids')->nullable();
            $table->text('billing_cycles')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Pedidos de subscrição feitos pela empresa: ativação ou renovação (a pagar; a plataforma confirma
        // depois de faturar) e subida de plano a meio do período (já aplicada; fica a diferença a faturar).
        Schema::create('subscription_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            // activation | renewal | upgrade
            $table->string('kind', 12);
            $table->foreignId('plan_id')->constrained('plans');
            $table->foreignId('from_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('billing_cycle', 10);
            $table->unsignedInteger('list_price_cents');
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('total_cents');
            $table->char('currency', 3)->default('EUR');
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            // pending | confirmed | canceled | expired
            $table->string('status', 12)->default('pending');
            $table->timestamp('period_starts_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('invoice_reference', 60)->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['tenant_id', 'id']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedSmallInteger('trial_days')->nullable();
            $table->timestamps();

            $table->unique(['coupon_id', 'tenant_id']);
        });

        // Mudança marcada para mais tarde (descida de plano ou troca de ciclo no fim do período).
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('scheduled_plan_id')->nullable()->after('plan_id')->constrained('plans')->nullOnDelete();
            $table->string('scheduled_billing_cycle', 10)->nullable()->after('scheduled_plan_id');
            $table->timestamp('scheduled_change_at')->nullable()->after('scheduled_billing_cycle');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scheduled_plan_id');
            $table->dropColumn(['scheduled_billing_cycle', 'scheduled_change_at']);
        });
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('subscription_orders');
        Schema::dropIfExists('coupons');
    }
};
