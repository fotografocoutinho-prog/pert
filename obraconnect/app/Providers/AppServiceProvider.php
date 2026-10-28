<?php

namespace App\Providers;

use App\Access\AccessResolver;
use App\Access\PermissionRegistry;
use App\Audit\AuthEventSubscriber;
use App\Billing\PlanLimits;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Instalação nova sem APP_KEY: a página /instalar tem de abrir para a gerar,
        // e uma sessão cifrada precisaria da chave que ainda não existe.
        if (blank(config('app.key'))) {
            config(['session.encrypt' => false]);
        }

        $this->app->scoped(TenantContext::class);
        $this->app->scoped(AccessResolver::class);
        $this->app->singleton(PlanLimits::class);
    }

    public function boot(): void
    {
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        $this->configureSecurity();
        $this->configureAuthorization();
        $this->configureQueuedTenantContext();
        $this->configureRateLimiting();

        Event::subscribe(AuthEventSubscriber::class);

        // Fortify corre sem vistas: os emails apontam para as páginas da SPA.
        ResetPassword::createUrlUsing(
            fn (User $user, string $token) => url('/repor-password/'.$token).'?email='.urlencode($user->getEmailForPasswordReset())
        );
    }

    private function configureSecurity(): void
    {
        if (config('obraconnect.security.force_https')) {
            URL::forceScheme('https');
        }

        if ($proxies = config('obraconnect.security.trusted_proxies')) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        Password::defaults(function () {
            $rule = Password::min(config('obraconnect.security.password_min_length'))->letters()->numbers();

            return config('obraconnect.security.password_check_uncompromised') ? $rule->uncompromised() : $rule;
        });
    }

    /**
     * Qualquer chave do catálogo (ex.: "members.invite") é resolvida pelas
     * permissões da associação ativa, por isso controladores usam $this->authorize().
     */
    private function configureAuthorization(): void
    {
        Gate::before(function (User $user, string $ability, array $arguments) {
            if (! PermissionRegistry::has($ability)) {
                return null;
            }

            $context = app(TenantContext::class);

            if (! $context->hasTenant()) {
                return false;
            }

            // Suporte técnico autorizado: apenas permissões de consulta.
            if ($context->isSupportSession()) {
                return str_ends_with($ability, '.view');
            }

            $membership = $context->membership();

            if ($membership === null || (int) $membership->user_id !== (int) $user->getKey()) {
                return false;
            }

            return app(AccessResolver::class)->can($membership, $ability, $arguments[0] ?? null);
        });
    }

    /**
     * Restaura a empresa ao processar trabalhos em fila. O ID viaja no Context do
     * Laravel (escrito por TenantContext::set) dentro do payload do trabalho.
     */
    private function configureQueuedTenantContext(): void
    {
        Context::hydrated(function (ContextRepository $context): void {
            if (! $context->hasHidden(TenantContext::CONTEXT_KEY)) {
                return;
            }

            $tenantId = (int) $context->getHidden(TenantContext::CONTEXT_KEY);
            $tenants = app(TenantContext::class);

            // Fila "sync": o trabalho corre dentro do pedido, que já tem o contexto completo.
            if ($tenants->id() === $tenantId) {
                return;
            }

            if ($tenant = Tenant::query()->find($tenantId)) {
                $tenants->set($tenant);
            }
        });
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('invitations', fn (Request $request) => Limit::perHour(30)->by('inv:'.($request->user()?->current_tenant_id ?: $request->ip())));

        RateLimiter::for('invitation-lookup', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        RateLimiter::for('exports', fn (Request $request) => Limit::perHour(5)->by('exp:'.($request->user()?->current_tenant_id ?: $request->ip())));
    }
}
