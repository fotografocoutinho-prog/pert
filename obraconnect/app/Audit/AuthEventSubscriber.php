<?php

namespace App\Audit;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/**
 * Regista eventos de autenticação e segurança da conta.
 */
class AuthEventSubscriber
{
    public function __construct(private readonly Auditor $auditor) {}

    public function onLogin(Login $event): void
    {
        /** @var User $user */
        $user = $event->user;
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => request()?->ip()])->saveQuietly();

        $this->record('auth.login', $user);
    }

    public function onLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->record('auth.logout', $event->user);
        }
    }

    public function onFailed(Failed $event): void
    {
        $this->auditor->record('auth.failed', null, [
            'email' => Str::lower((string) ($event->credentials['email'] ?? '')),
        ], tenantId: false, actorType: 'system');
    }

    public function onLockout(Lockout $event): void
    {
        $this->auditor->record('auth.lockout', null, [
            'email' => Str::lower((string) $event->request->input('email', '')),
        ], tenantId: false, actorType: 'system');
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        $this->record('auth.password_reset', $event->user);
    }

    public function onVerified(Verified $event): void
    {
        $this->record('auth.email_verified', $event->user);
    }

    public function onTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->record('auth.2fa_enabled', $event->user);
    }

    public function onTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->record('auth.2fa_disabled', $event->user);
    }

    public function onTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->record('auth.2fa_failed', $event->user);
    }

    public function onRecoveryCodeReplaced(RecoveryCodeReplaced $event): void
    {
        $this->record('auth.2fa_recovery_code_used', $event->user);
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Logout::class => 'onLogout',
            Failed::class => 'onFailed',
            Lockout::class => 'onLockout',
            PasswordReset::class => 'onPasswordReset',
            Verified::class => 'onVerified',
            TwoFactorAuthenticationConfirmed::class => 'onTwoFactorConfirmed',
            TwoFactorAuthenticationDisabled::class => 'onTwoFactorDisabled',
            TwoFactorAuthenticationFailed::class => 'onTwoFactorFailed',
            RecoveryCodeReplaced::class => 'onRecoveryCodeReplaced',
        ];
    }

    /**
     * Eventos de conta ficam associados à empresa ativa do utilizador (se tiver),
     * para o administrador da empresa os ver na auditoria.
     */
    private function record(string $event, mixed $user): void
    {
        if (! $user instanceof User) {
            return;
        }

        $this->auditor->record($event, $user, [], tenantId: $user->current_tenant_id ?? false, actor: $user, actorType: 'user');
    }
}
