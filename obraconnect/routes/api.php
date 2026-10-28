<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvitationAcceptanceController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\Platform;
use App\Http\Controllers\Api\V1\PublicPlanController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SupportGrantController;
use Illuminate\Support\Facades\Route;

/*
| API v1. Rotas de empresa usam o middleware "tenant": a empresa vem sempre da
| conta autenticada, nunca de parâmetros do pedido.
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    // Público
    Route::get('health', HealthController::class)->name('health');
    Route::get('plans', PublicPlanController::class)->name('plans');
    Route::get('invitations/lookup/{token}', [InvitationAcceptanceController::class, 'show'])
        ->middleware('throttle:invitation-lookup')->name('invitations.lookup');

    Route::middleware(['auth:sanctum', 'account.active'])->group(function () {

        // Conta (sem empresa obrigatória)
        Route::get('me', [MeController::class, 'show'])->name('me');
        Route::post('me/tenants/{tenantId}/switch', [MeController::class, 'switchTenant'])
            ->whereNumber('tenantId')->name('me.switch');
        Route::post('invitations/accept', [InvitationAcceptanceController::class, 'store'])->name('invitations.accept');

        // Empresa ativa
        Route::middleware(['verified', 'tenant'])->group(function () {
            Route::get('company', [CompanyController::class, 'show'])->name('company.show');
            Route::put('company', [CompanyController::class, 'update'])->name('company.update');
            Route::put('company/security', [CompanyController::class, 'updateSecurity'])->name('company.security');
            Route::get('company/logo', [CompanyController::class, 'logo'])->name('company.logo');
            Route::post('company/logo', [CompanyController::class, 'uploadLogo'])->name('company.logo.store');
            Route::delete('company/logo', [CompanyController::class, 'deleteLogo'])->name('company.logo.destroy');

            Route::get('members', [MemberController::class, 'index'])->name('members.index');
            Route::patch('members/{membership}', [MemberController::class, 'update'])->name('members.update');
            Route::delete('members/{membership}', [MemberController::class, 'destroy'])->name('members.destroy');

            Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
            Route::post('invitations', [InvitationController::class, 'store'])
                ->middleware('throttle:invitations')->name('invitations.store');
            Route::post('invitations/{invitation}/resend', [InvitationController::class, 'resend'])
                ->middleware('throttle:invitations')->name('invitations.resend');
            Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');

            Route::get('permissions', PermissionController::class)->name('permissions.index');
            Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
            Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
            Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::post('roles/{role}/reset', [RoleController::class, 'reset'])->name('roles.reset');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

            Route::get('audit-logs', AuditLogController::class)->name('audit.index');
            Route::get('subscription', SubscriptionController::class)->name('subscription.show');

            Route::get('support-grants', [SupportGrantController::class, 'index'])->name('support-grants.index');
            Route::post('support-grants', [SupportGrantController::class, 'store'])->name('support-grants.store');
            Route::delete('support-grants/{supportAccessGrant}', [SupportGrantController::class, 'destroy'])->name('support-grants.destroy');

            Route::get('exports', [ExportController::class, 'index'])->name('exports.index');
            Route::post('exports', [ExportController::class, 'store'])->middleware('throttle:exports')->name('exports.store');
            Route::get('exports/{tenantExport}/download', [ExportController::class, 'download'])->name('exports.download');
        });

        // Backoffice da plataforma (exige papel de plataforma e 2FA)
        Route::prefix('platform')->name('platform.')->middleware('platform')->group(function () {
            Route::get('dashboard', Platform\DashboardController::class)->name('dashboard');

            Route::get('tenants', [Platform\TenantController::class, 'index'])->name('tenants.index');
            Route::get('tenants/{tenant}', [Platform\TenantController::class, 'show'])->name('tenants.show');
            Route::post('tenants/{tenant}/suspend', [Platform\TenantController::class, 'suspend'])
                ->middleware('platform:super_admin')->name('tenants.suspend');
            Route::post('tenants/{tenant}/reactivate', [Platform\TenantController::class, 'reactivate'])
                ->middleware('platform:super_admin')->name('tenants.reactivate');
            Route::put('tenants/{tenant}/subscription', [Platform\TenantController::class, 'updateSubscription'])
                ->middleware('platform:billing')->name('tenants.subscription');

            Route::get('plans', [Platform\PlanController::class, 'index'])->name('plans.index');
            Route::post('plans', [Platform\PlanController::class, 'store'])->middleware('platform:billing')->name('plans.store');
            Route::put('plans/{plan}', [Platform\PlanController::class, 'update'])->middleware('platform:billing')->name('plans.update');

            Route::middleware('platform:support')->group(function () {
                Route::get('support-grants', [Platform\SupportController::class, 'index'])->name('support.index');
                Route::post('support-grants/{grantId}/enter', [Platform\SupportController::class, 'enter'])
                    ->whereNumber('grantId')->name('support.enter');
                Route::post('support/leave', [Platform\SupportController::class, 'leave'])->name('support.leave');
                Route::get('security-logs', Platform\SecurityLogController::class)->name('security-logs');
            });
        });
    });
});
