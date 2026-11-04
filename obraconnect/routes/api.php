<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CostCenterController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\DiaryEntryController;
use App\Http\Controllers\Api\V1\DiaryPhotoController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\DocumentFolderController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvitationAcceptanceController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\Platform;
use App\Http\Controllers\Api\V1\ProjectCalendarController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectMemberController;
use App\Http\Controllers\Api\V1\PublicPlanController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SpecialtyController;
use App\Http\Controllers\Api\V1\StructureController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SupportGrantController;
use App\Http\Controllers\Api\V1\WorkCalendarController;
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

            Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index');
            Route::post('departments', [DepartmentController::class, 'store'])->name('departments.store');
            Route::put('departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
            Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy');
            Route::put('departments/{department}/members', [DepartmentController::class, 'syncMembers'])->name('departments.members');

            Route::get('cost-centers', [CostCenterController::class, 'index'])->name('cost-centers.index');
            Route::post('cost-centers', [CostCenterController::class, 'store'])->name('cost-centers.store');
            Route::put('cost-centers/{costCenter}', [CostCenterController::class, 'update'])->name('cost-centers.update');
            Route::delete('cost-centers/{costCenter}', [CostCenterController::class, 'destroy'])->name('cost-centers.destroy');

            Route::get('specialties', [SpecialtyController::class, 'index'])->name('specialties.index');
            Route::post('specialties', [SpecialtyController::class, 'store'])->name('specialties.store');
            Route::post('specialties/defaults', [SpecialtyController::class, 'addDefaults'])->name('specialties.defaults');
            Route::put('specialties/{specialty}', [SpecialtyController::class, 'update'])->name('specialties.update');
            Route::delete('specialties/{specialty}', [SpecialtyController::class, 'destroy'])->name('specialties.destroy');

            Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
            Route::get('clients/options', [ClientController::class, 'options'])->name('clients.options');
            Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
            Route::get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');
            Route::put('clients/{client}', [ClientController::class, 'update'])->name('clients.update');
            Route::delete('clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');

            Route::get('work-calendars', [WorkCalendarController::class, 'index'])->name('calendars.index');
            Route::post('work-calendars', [WorkCalendarController::class, 'store'])->name('calendars.store');
            Route::put('work-calendars/{workCalendar}', [WorkCalendarController::class, 'update'])->name('calendars.update');
            Route::delete('work-calendars/{workCalendar}', [WorkCalendarController::class, 'destroy'])->name('calendars.destroy');
            Route::get('work-calendars/{workCalendar}/holidays', [WorkCalendarController::class, 'holidays'])->name('calendars.holidays.index');
            Route::post('work-calendars/{workCalendar}/holidays', [WorkCalendarController::class, 'storeHolidays'])->name('calendars.holidays.store');
            Route::delete('work-calendars/{workCalendar}/holidays', [WorkCalendarController::class, 'destroyHolidays'])->name('calendars.holidays.destroy');
            Route::post('work-calendars/{workCalendar}/national-holidays', [WorkCalendarController::class, 'addNationalHolidays'])->name('calendars.national');

            // Obras. O acesso a cada obra é decidido no controlador (perfil na empresa ou na equipa).
            Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
            Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
            Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
            Route::put('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');

            // Recursos dentro da obra: scopeBindings garante que pertencem à obra do endereço.
            Route::scopeBindings()->prefix('projects/{project}')->name('projects.')->group(function () {
                Route::get('calendar', ProjectCalendarController::class)->name('calendar');

                Route::get('members', [ProjectMemberController::class, 'index'])->name('members.index');
                Route::get('members/options', [ProjectMemberController::class, 'options'])->name('members.options');
                Route::post('members', [ProjectMemberController::class, 'store'])->name('members.store');
                Route::patch('members/{member}', [ProjectMemberController::class, 'update'])->name('members.update');
                Route::delete('members/{member}', [ProjectMemberController::class, 'destroy'])->name('members.destroy');

                Route::get('diary', [DiaryEntryController::class, 'index'])->name('diary.index');
                Route::post('diary', [DiaryEntryController::class, 'store'])->name('diary.store');
                Route::get('diary/{diaryEntry}', [DiaryEntryController::class, 'show'])->name('diary.show');
                Route::put('diary/{diaryEntry}', [DiaryEntryController::class, 'update'])->name('diary.update');
                Route::delete('diary/{diaryEntry}', [DiaryEntryController::class, 'destroy'])->name('diary.destroy');
                Route::post('diary/{diaryEntry}/submit', [DiaryEntryController::class, 'submit'])->name('diary.submit');

                Route::post('diary/{diaryEntry}/photos', [DiaryPhotoController::class, 'store'])
                    ->middleware('throttle:uploads')->name('diary.photos.store');
                Route::patch('diary/{diaryEntry}/photos/{photo}', [DiaryPhotoController::class, 'update'])->name('diary.photos.update');
                Route::delete('diary/{diaryEntry}/photos/{photo}', [DiaryPhotoController::class, 'destroy'])->name('diary.photos.destroy');

                Route::get('media/{media}/{variant}', [MediaController::class, 'show'])
                    ->whereIn('variant', ['thumb', 'preview', 'original'])->name('media.show');

                Route::post('folders', [DocumentFolderController::class, 'store'])->name('folders.store');
                Route::patch('folders/{folder}', [DocumentFolderController::class, 'update'])->name('folders.update');
                Route::delete('folders/{folder}', [DocumentFolderController::class, 'destroy'])->name('folders.destroy');

                Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
                Route::post('documents', [DocumentController::class, 'store'])
                    ->middleware('throttle:uploads')->name('documents.store');
                Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
                Route::patch('documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
                Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
                Route::post('documents/{document}/versions', [DocumentController::class, 'storeVersion'])
                    ->middleware('throttle:uploads')->name('documents.versions.store');
                Route::get('documents/{document}/versions/{version}/download', [DocumentController::class, 'download'])
                    ->name('documents.versions.download');

                Route::get('structure', [StructureController::class, 'index'])->name('structure.index');
                Route::post('locations', [StructureController::class, 'storeLocation'])->name('locations.store');
                Route::patch('locations/{location}', [StructureController::class, 'updateLocation'])->name('locations.update');
                Route::delete('locations/{location}', [StructureController::class, 'destroyLocation'])->name('locations.destroy');
                Route::post('elements', [StructureController::class, 'storeElement'])->name('elements.store');
                Route::patch('elements/{element}', [StructureController::class, 'updateElement'])->name('elements.update');
                Route::delete('elements/{element}', [StructureController::class, 'destroyElement'])->name('elements.destroy');
                Route::get('elements/{element}/links', [StructureController::class, 'elementLinks'])->name('elements.links');
                Route::get('locations/{location}/links', [StructureController::class, 'locationLinks'])->name('locations.links');
            });
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
