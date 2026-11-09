<?php

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\BudgetController;
use App\Http\Controllers\Api\V1\CertificateController;
use App\Http\Controllers\Api\V1\ChecklistTemplateController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\ContractController;
use App\Http\Controllers\Api\V1\CostCenterController;
use App\Http\Controllers\Api\V1\CostController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\DiaryEntryController;
use App\Http\Controllers\Api\V1\DiaryPhotoController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\DocumentFolderController;
use App\Http\Controllers\Api\V1\EquipmentController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\ForecastController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InspectionController;
use App\Http\Controllers\Api\V1\InspectionItemController;
use App\Http\Controllers\Api\V1\InvitationAcceptanceController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\InvoiceDraftController;
use App\Http\Controllers\Api\V1\IssueController;
use App\Http\Controllers\Api\V1\MeasurementController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\PartnerController;
use App\Http\Controllers\Api\V1\PartnerPortalController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\PlanningController;
use App\Http\Controllers\Api\V1\Platform;
use App\Http\Controllers\Api\V1\Portal;
use App\Http\Controllers\Api\V1\PriceLibraryController;
use App\Http\Controllers\Api\V1\ProjectCalendarController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectMemberController;
use App\Http\Controllers\Api\V1\PublicPlanController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SpecialtyController;
use App\Http\Controllers\Api\V1\StructureController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SupplierDocumentController;
use App\Http\Controllers\Api\V1\SupportGrantController;
use App\Http\Controllers\Api\V1\TimeEntryController;
use App\Http\Controllers\Api\V1\WorkCalendarController;
use App\Http\Controllers\Api\V1\WorkerController;
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
    Route::get('portal-invitations/{token}', [Portal\PortalInvitationController::class, 'show'])
        ->middleware('throttle:invitation-lookup')->name('portal.invitations.lookup');

    Route::middleware(['auth:sanctum', 'account.active'])->group(function () {

        // Conta (sem empresa obrigatória)
        Route::get('me', [MeController::class, 'show'])->name('me');
        Route::post('me/tenants/{tenantId}/switch', [MeController::class, 'switchTenant'])
            ->whereNumber('tenantId')->name('me.switch');
        Route::post('invitations/accept', [InvitationAcceptanceController::class, 'store'])->name('invitations.accept');

        // Portal de subempreiteiros (sem empresa própria: o acesso diz de que empresa e de que subempreiteiro)
        Route::post('portal-invitations/accept', [Portal\PortalInvitationController::class, 'accept'])->name('portal.invitations.accept');
        Route::get('portal/accesses', [Portal\PortalController::class, 'accesses'])->name('portal.accesses');
        Route::prefix('portal/{access}')->whereNumber('access')->middleware('portal')->name('portal.')->group(function () {
            Route::get('contracts', [Portal\PortalController::class, 'contracts'])->name('contracts');
            Route::get('contracts/{contract}', [Portal\PortalController::class, 'contract'])->whereNumber('contract')->name('contract');
            Route::post('contracts/{contract}/proposals', [Portal\PortalController::class, 'propose'])->whereNumber('contract')->middleware('throttle:uploads')->name('proposals');
            Route::get('documents', [Portal\PortalController::class, 'documents'])->name('documents');
            Route::post('documents', [Portal\PortalController::class, 'storeDocument'])->middleware('throttle:uploads')->name('documents.store');
            Route::get('documents/{document}/file', [Portal\PortalController::class, 'documentFile'])->whereNumber('document')->name('documents.file');
        });

        // Empresa ativa
        Route::middleware(['verified', 'tenant'])->group(function () {
            Route::get('company', [CompanyController::class, 'show'])->name('company.show');
            Route::put('company', [CompanyController::class, 'update'])->name('company.update');
            Route::put('company/security', [CompanyController::class, 'updateSecurity'])->name('company.security');
            Route::get('company/logo', [CompanyController::class, 'logo'])->name('company.logo');
            Route::post('company/logo', [CompanyController::class, 'uploadLogo'])->name('company.logo.store');
            Route::delete('company/logo', [CompanyController::class, 'deleteLogo'])->name('company.logo.destroy');
            Route::get('company/alerts', [CompanyController::class, 'alerts'])->name('company.alerts');
            Route::put('company/alerts', [CompanyController::class, 'updateAlerts'])->name('company.alerts.update');
            Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');

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

            Route::get('checklist-templates', [ChecklistTemplateController::class, 'index'])->name('checklists.index');
            Route::post('checklist-templates', [ChecklistTemplateController::class, 'store'])->name('checklists.store');
            Route::get('checklist-templates/{checklistTemplate}', [ChecklistTemplateController::class, 'show'])->name('checklists.show');
            Route::put('checklist-templates/{checklistTemplate}', [ChecklistTemplateController::class, 'update'])->name('checklists.update');
            Route::delete('checklist-templates/{checklistTemplate}', [ChecklistTemplateController::class, 'destroy'])->name('checklists.destroy');

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

            // Fase 4: biblioteca de preços, fornecedores e subempreiteiros, trabalhadores, equipamentos, finanças.
            Route::get('price-library', [PriceLibraryController::class, 'index'])->name('prices.index');
            Route::post('price-library/resources', [PriceLibraryController::class, 'storeResource'])->name('prices.resources.store');
            Route::put('price-library/resources/{priceResource}', [PriceLibraryController::class, 'updateResource'])->name('prices.resources.update');
            Route::delete('price-library/resources/{priceResource}', [PriceLibraryController::class, 'destroyResource'])->name('prices.resources.destroy');
            Route::post('price-library/articles', [PriceLibraryController::class, 'storeArticle'])->name('prices.articles.store');
            Route::put('price-library/articles/{article}', [PriceLibraryController::class, 'updateArticle'])->name('prices.articles.update');
            Route::delete('price-library/articles/{article}', [PriceLibraryController::class, 'destroyArticle'])->name('prices.articles.destroy');

            Route::get('partners', [PartnerController::class, 'index'])->name('partners.index');
            Route::post('partners', [PartnerController::class, 'store'])->name('partners.store');
            Route::put('partners/{partner}', [PartnerController::class, 'update'])->name('partners.update');
            Route::delete('partners/{partner}', [PartnerController::class, 'destroy'])->name('partners.destroy');
            Route::post('partners/{partner}/documents', [PartnerController::class, 'storeDocument'])->name('partners.documents.store');
            Route::delete('partners/{partner}/documents/{document}', [PartnerController::class, 'destroyDocument'])->scopeBindings()->name('partners.documents.destroy');
            Route::get('partners/{partner}/documents/{document}/file', [PartnerController::class, 'documentFile'])->scopeBindings()->name('partners.documents.file');
            Route::get('partners/{partner}/portal-users', [PartnerPortalController::class, 'index'])->name('partners.portal.index');
            Route::post('partners/{partner}/portal-users', [PartnerPortalController::class, 'store'])->name('partners.portal.store');
            Route::post('partners/{partner}/portal-users/{portalUser}/resend', [PartnerPortalController::class, 'resend'])->name('partners.portal.resend');
            Route::delete('partners/{partner}/portal-users/{portalUser}', [PartnerPortalController::class, 'destroy'])->name('partners.portal.destroy');

            Route::get('workers', [WorkerController::class, 'index'])->name('workers.index');
            Route::post('workers', [WorkerController::class, 'store'])->name('workers.store');
            Route::put('workers/{worker}', [WorkerController::class, 'update'])->name('workers.update');
            Route::delete('workers/{worker}', [WorkerController::class, 'destroy'])->name('workers.destroy');
            Route::get('time-entries/payroll', [TimeEntryController::class, 'payroll'])->name('time.payroll');

            Route::get('equipment', [EquipmentController::class, 'index'])->name('equipment.index');
            Route::post('equipment', [EquipmentController::class, 'store'])->name('equipment.store');
            Route::get('equipment/qr/{token}', [EquipmentController::class, 'byToken'])->where('token', '[A-Za-z0-9]+')->name('equipment.token');
            Route::get('equipment/{equipment}', [EquipmentController::class, 'show'])->name('equipment.show');
            Route::put('equipment/{equipment}', [EquipmentController::class, 'update'])->name('equipment.update');
            Route::delete('equipment/{equipment}', [EquipmentController::class, 'destroy'])->name('equipment.destroy');
            Route::get('equipment/{equipment}/qr', [EquipmentController::class, 'qr'])->name('equipment.qr');
            Route::post('equipment/{equipment}/maintenance', [EquipmentController::class, 'storeMaintenance'])->name('equipment.maintenance.store');
            Route::delete('equipment/{equipment}/maintenance/{maintenanceRecord}', [EquipmentController::class, 'destroyMaintenance'])->scopeBindings()->name('equipment.maintenance.destroy');

            Route::get('finance', [CostController::class, 'finance'])->name('finance');

            // Pré-faturas (documentos internos sem valor fiscal, para o programa de faturação certificado)
            Route::get('invoice-drafts', [InvoiceDraftController::class, 'index'])->name('invoices.index');
            Route::get('invoice-drafts/export', [InvoiceDraftController::class, 'export'])->name('invoices.export');
            Route::post('invoice-drafts', [InvoiceDraftController::class, 'store'])->name('invoices.store');
            Route::get('invoice-drafts/{invoiceDraft}', [InvoiceDraftController::class, 'show'])->whereNumber('invoiceDraft')->name('invoices.show');
            Route::put('invoice-drafts/{invoiceDraft}', [InvoiceDraftController::class, 'update'])->whereNumber('invoiceDraft')->name('invoices.update');
            Route::delete('invoice-drafts/{invoiceDraft}', [InvoiceDraftController::class, 'destroy'])->whereNumber('invoiceDraft')->name('invoices.destroy');
            Route::get('invoice-drafts/{invoiceDraft}/pdf', [InvoiceDraftController::class, 'pdf'])->whereNumber('invoiceDraft')->name('invoices.pdf');
            Route::post('invoice-drafts/{invoiceDraft}/actions/{action}', [InvoiceDraftController::class, 'transition'])
                ->whereNumber('invoiceDraft')->whereIn('action', ['ready', 'reopen', 'invoice', 'cancel'])->name('invoices.transition');

            // Faturas e guias de fornecedores (lidas pelo DocIntel, revistas e lançadas nas compras ou nos custos)
            Route::get('supplier-documents', [SupplierDocumentController::class, 'index'])->name('supplier-docs.index');
            Route::post('supplier-documents', [SupplierDocumentController::class, 'store'])->middleware('throttle:uploads')->name('supplier-docs.store');
            Route::get('supplier-documents/{supplierDocument}', [SupplierDocumentController::class, 'show'])->whereNumber('supplierDocument')->name('supplier-docs.show');
            Route::put('supplier-documents/{supplierDocument}', [SupplierDocumentController::class, 'update'])->whereNumber('supplierDocument')->name('supplier-docs.update');
            Route::delete('supplier-documents/{supplierDocument}', [SupplierDocumentController::class, 'destroy'])->whereNumber('supplierDocument')->name('supplier-docs.destroy');
            Route::get('supplier-documents/{supplierDocument}/file', [SupplierDocumentController::class, 'file'])->whereNumber('supplierDocument')->name('supplier-docs.file');
            Route::post('supplier-documents/{supplierDocument}/actions/{action}', [SupplierDocumentController::class, 'transition'])
                ->whereNumber('supplierDocument')->whereIn('action', ['post', 'unpost', 'reject', 'reopen', 'read'])->name('supplier-docs.transition');

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

                Route::get('inspections', [InspectionController::class, 'index'])->name('inspections.index');
                Route::post('inspections', [InspectionController::class, 'store'])->name('inspections.store');
                Route::get('inspections/{inspection}', [InspectionController::class, 'show'])->name('inspections.show');
                Route::get('inspections/{inspection}/report', [InspectionController::class, 'report'])->name('inspections.report');
                Route::put('inspections/{inspection}', [InspectionController::class, 'update'])->name('inspections.update');
                Route::delete('inspections/{inspection}', [InspectionController::class, 'destroy'])->name('inspections.destroy');
                Route::post('inspections/{inspection}/actions/{action}', [InspectionController::class, 'transition'])
                    ->where('action', '[a-z_]+')->name('inspections.transition');
                Route::post('inspections/{inspection}/reinspection', [InspectionController::class, 'reinspect'])->name('inspections.reinspect');
                Route::post('inspections/{inspection}/release-hold', [InspectionController::class, 'releaseHold'])->name('inspections.release-hold');
                Route::patch('inspections/{inspection}/items/{item}', [InspectionItemController::class, 'update'])->name('inspections.items.update');
                Route::post('inspections/{inspection}/items/{item}/photos', [InspectionItemController::class, 'storePhoto'])
                    ->middleware('throttle:uploads')->name('inspections.photos.store');
                Route::delete('inspections/{inspection}/items/{item}/photos/{photo}', [InspectionItemController::class, 'destroyPhoto'])
                    ->name('inspections.photos.destroy');

                Route::get('issues', [IssueController::class, 'index'])->name('issues.index');
                Route::get('issues/assignees', [IssueController::class, 'assignees'])->name('issues.assignees');
                Route::post('issues', [IssueController::class, 'store'])->name('issues.store');
                Route::get('issues/{issue}', [IssueController::class, 'show'])->name('issues.show');
                Route::put('issues/{issue}', [IssueController::class, 'update'])->name('issues.update');
                Route::post('issues/{issue}/actions/{action}', [IssueController::class, 'transition'])
                    ->where('action', '[a-z_]+')->name('issues.transition');
                Route::post('issues/{issue}/comments', [IssueController::class, 'comment'])->name('issues.comment');
                Route::post('issues/{issue}/photos', [IssueController::class, 'storePhoto'])
                    ->middleware('throttle:uploads')->name('issues.photos.store');
                Route::delete('issues/{issue}/photos/{photo}', [IssueController::class, 'destroyPhoto'])->name('issues.photos.destroy');

                Route::get('budgets', [BudgetController::class, 'index'])->name('budgets.index');
                Route::post('budgets', [BudgetController::class, 'store'])->name('budgets.store');
                Route::get('budgets/{budget}', [BudgetController::class, 'show'])->name('budgets.show');
                Route::put('budgets/{budget}', [BudgetController::class, 'update'])->name('budgets.update');
                Route::delete('budgets/{budget}', [BudgetController::class, 'destroy'])->name('budgets.destroy');
                Route::post('budgets/{budget}/approve', [BudgetController::class, 'approve'])->name('budgets.approve');
                Route::post('budgets/{budget}/revise', [BudgetController::class, 'revise'])->name('budgets.revise');
                Route::post('budgets/{budget}/chapters', [BudgetController::class, 'storeChapter'])->name('budgets.chapters.store');
                Route::put('budgets/{budget}/chapters/{chapter}', [BudgetController::class, 'updateChapter'])->name('budgets.chapters.update');
                Route::delete('budgets/{budget}/chapters/{chapter}', [BudgetController::class, 'destroyChapter'])->name('budgets.chapters.destroy');
                Route::post('budgets/{budget}/items', [BudgetController::class, 'storeItem'])->name('budgets.items.store');
                Route::put('budgets/{budget}/items/{item}', [BudgetController::class, 'updateItem'])->name('budgets.items.update');
                Route::delete('budgets/{budget}/items/{item}', [BudgetController::class, 'destroyItem'])->name('budgets.items.destroy');
                Route::post('budgets/{budget}/import', [BudgetController::class, 'import'])->middleware('throttle:uploads')->name('budgets.import');
                Route::get('budgets/{budget}/export', [BudgetController::class, 'export'])->name('budgets.export');

                Route::get('measurements', [MeasurementController::class, 'index'])->name('measurements.index');
                Route::post('measurements', [MeasurementController::class, 'store'])->name('measurements.store');
                Route::put('measurements/{measurement}', [MeasurementController::class, 'update'])->name('measurements.update');
                Route::delete('measurements/{measurement}', [MeasurementController::class, 'destroy'])->name('measurements.destroy');

                Route::get('certificates', [CertificateController::class, 'index'])->name('certificates.index');
                Route::post('certificates', [CertificateController::class, 'store'])->name('certificates.store');
                Route::get('certificates/{certificate}', [CertificateController::class, 'show'])->name('certificates.show');
                Route::delete('certificates/{certificate}', [CertificateController::class, 'destroy'])->name('certificates.destroy');
                Route::put('certificates/{certificate}/lines/{line}', [CertificateController::class, 'updateLine'])->name('certificates.lines.update');
                Route::post('certificates/{certificate}/submit', [CertificateController::class, 'submit'])->name('certificates.submit');
                Route::post('certificates/{certificate}/approve', [CertificateController::class, 'approve'])->name('certificates.approve');
                Route::post('certificates/{certificate}/reopen', [CertificateController::class, 'reopen'])->name('certificates.reopen');

                Route::get('contracts', [ContractController::class, 'index'])->name('contracts.index');
                Route::post('contracts', [ContractController::class, 'store'])->name('contracts.store');
                Route::put('contracts/{contract}', [ContractController::class, 'update'])->name('contracts.update');
                Route::delete('contracts/{contract}', [ContractController::class, 'destroy'])->name('contracts.destroy');
                Route::post('contracts/{contract}/actions/{action}', [ContractController::class, 'transition'])->whereIn('action', ['activate', 'close'])->name('contracts.transition');

                Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchases.index');
                Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchases.store');
                Route::put('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->name('purchases.update');
                Route::delete('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->name('purchases.destroy');
                Route::post('purchase-orders/{purchaseOrder}/actions/{action}', [PurchaseOrderController::class, 'transition'])->whereIn('action', ['approve', 'receive', 'invoice', 'cancel'])->name('purchases.transition');

                Route::get('costs', [CostController::class, 'show'])->name('costs.show');
                Route::get('costs/export', [CostController::class, 'export'])->name('costs.export');
                Route::put('costs/ceiling', [CostController::class, 'updateCeiling'])->name('costs.ceiling');
                Route::post('cost-entries', [CostController::class, 'storeEntry'])->name('costs.entries.store');
                Route::delete('cost-entries/{costEntry}', [CostController::class, 'destroyEntry'])->name('costs.entries.destroy');
                Route::get('forecast', [ForecastController::class, 'show'])->name('forecast.show');

                Route::get('time-entries', [TimeEntryController::class, 'index'])->name('time.index');
                Route::post('time-entries', [TimeEntryController::class, 'store'])->name('time.store');
                Route::post('time-entries/{decision}', [TimeEntryController::class, 'decide'])->whereIn('decision', ['approve', 'reject'])->name('time.decide');
                Route::delete('time-entries/{timeEntry}', [TimeEntryController::class, 'destroy'])->name('time.destroy');

                Route::get('equipment-usages', [EquipmentController::class, 'projectUsages'])->name('equipment.usages.index');
                Route::post('equipment-usages', [EquipmentController::class, 'storeUsage'])->name('equipment.usages.store');
                Route::delete('equipment-usages/{equipmentUsage}', [EquipmentController::class, 'destroyUsage'])->name('equipment.usages.destroy');

                Route::get('planning', [PlanningController::class, 'show'])->name('planning.show');
                Route::post('activities', [PlanningController::class, 'storeActivity'])->name('activities.store');
                Route::put('activities/{activity}', [PlanningController::class, 'updateActivity'])->name('activities.update');
                Route::delete('activities/{activity}', [PlanningController::class, 'destroyActivity'])->name('activities.destroy');
                Route::post('activities/{activity}/progress', [PlanningController::class, 'progress'])->name('activities.progress');
                Route::post('dependencies', [PlanningController::class, 'storeDependency'])->name('dependencies.store');
                Route::delete('dependencies/{dependency}', [PlanningController::class, 'destroyDependency'])->withoutScopedBindings()->name('dependencies.destroy');

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
            Route::get('system', [Platform\SystemController::class, 'show'])->name('system');
            Route::post('system/failed-jobs/{uuid}/retry', [Platform\SystemController::class, 'retry'])
                ->middleware('platform:super_admin')->name('system.retry');
            Route::delete('system/failed-jobs/{uuid}', [Platform\SystemController::class, 'forget'])
                ->middleware('platform:super_admin')->name('system.forget');

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
