<?php

use App\Http\Controllers\AbsenceRequestController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AiModelController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\CarePlanController;
use App\Http\Controllers\CarePlanGenerationController;
use App\Http\Controllers\CarePlanPdfController;
use App\Http\Controllers\CareReportController;
use App\Http\Controllers\CareTaskController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MedicationController;
use App\Http\Controllers\MyRosterController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QualityAssessmentController;
use App\Http\Controllers\ResidentController;
use App\Http\Controllers\RosterBlackoutDayController;
use App\Http\Controllers\RosterController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\ShiftTemplateController;
use App\Http\Controllers\ShiftWishController;
use App\Http\Controllers\SisController;
use App\Http\Controllers\SisGenerationController;
use App\Http\Controllers\SisPdfController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VitalSignController;
use App\Http\Controllers\WoundController;
use App\Models\Resident;
use App\Support\BrandPalette;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'branding' => BrandPalette::inertiaPayload(),
        'canLogin' => Route::has('login'),
        'canRegister' => false,
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', [DashboardController::class, 'show'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/care-reports', [CareReportController::class, 'index'])->name('care-reports.index');
    Route::post('/care-reports', [CareReportController::class, 'store'])->name('care-reports.store');
    Route::patch('/care-reports/{careReport}', [CareReportController::class, 'update'])->name('care-reports.update');
    Route::post('/care-reports/{careReport}/sign', [CareReportController::class, 'sign'])->name('care-reports.sign');

    Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
    Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
    Route::get('/staff/{staff}/edit', [StaffController::class, 'edit'])->name('staff.edit');
    Route::patch('/staff/{staff}', [StaffController::class, 'update'])->name('staff.update');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users/pdl', [UserController::class, 'storePdl'])->name('users.pdl.store');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');

    Route::get('/locations', [LocationController::class, 'index'])->name('locations.index');
    Route::post('/locations', [LocationController::class, 'store'])->name('locations.store');
    Route::get('/locations/{location}/edit', [LocationController::class, 'edit'])->name('locations.edit');
    Route::patch('/locations/{location}', [LocationController::class, 'update'])->name('locations.update');

    Route::get('/residents', [ResidentController::class, 'index'])->name('residents.index');
    Route::get('/residents/create', [ResidentController::class, 'create'])->name('residents.create');
    Route::post('/residents', [ResidentController::class, 'store'])->name('residents.store');
    Route::get('/residents/{resident}/edit', [ResidentController::class, 'edit'])->name('residents.edit');
    Route::get('/residents/{resident}', [ResidentController::class, 'show'])->name('residents.show');
    Route::patch('/residents/{resident}', [ResidentController::class, 'update'])->name('residents.update');

    Route::get('/residents/{resident}/sis', [SisController::class, 'show'])->name('residents.sis.show');
    Route::get('/residents/{resident}/sis/create', [SisController::class, 'create'])->name('residents.sis.create');
    Route::post('/residents/{resident}/sis', [SisController::class, 'store'])->name('residents.sis.store');
    Route::get('/residents/{resident}/sis/edit', [SisController::class, 'edit'])->name('residents.sis.edit');
    Route::patch('/residents/{resident}/sis', [SisController::class, 'update'])->name('residents.sis.update');
    Route::post('/residents/{resident}/sis/complete', [SisController::class, 'complete'])->name('residents.sis.complete');
    Route::post('/residents/{resident}/sis/evaluate', [SisController::class, 'evaluate'])->name('residents.sis.evaluate');
    Route::get('/residents/{resident}/sis/pdf', [SisPdfController::class, 'download'])->name('residents.sis.pdf');

    // Massnahmenplan (MP) - 1:1 zu Resident, setzt voraus dass SIS completed_at gesetzt hat
    Route::get('/residents/{resident}/care-plan', [CarePlanController::class, 'show'])->name('residents.care-plan.show');
    Route::get('/residents/{resident}/care-plan/create', [CarePlanController::class, 'create'])->name('residents.care-plan.create');
    Route::post('/residents/{resident}/care-plan', [CarePlanController::class, 'store'])->name('residents.care-plan.store');
    Route::get('/residents/{resident}/care-plan/edit', [CarePlanController::class, 'edit'])->name('residents.care-plan.edit');
    Route::patch('/residents/{resident}/care-plan', [CarePlanController::class, 'update'])->name('residents.care-plan.update');
    Route::post('/residents/{resident}/care-plan/evaluate', [CarePlanController::class, 'evaluate'])->name('residents.care-plan.evaluate');

    // KI-Erstellung des Massnahmenplans aus der SIS
    Route::post('/residents/{resident}/care-plan/generate', [CarePlanGenerationController::class, 'start'])->name('residents.care-plan.generate.start');
    Route::get('/residents/{resident}/care-plan/generate/{generation}', [CarePlanGenerationController::class, 'show'])->name('residents.care-plan.generate.show');
    Route::get('/residents/{resident}/care-plan/pdf', [CarePlanPdfController::class, 'download'])->name('residents.care-plan.pdf');

    Route::post('/residents/{resident}/sis/generate', [SisGenerationController::class, 'start'])->name('residents.sis.generate.start');
    Route::get('/residents/{resident}/sis/generate/{generation}', [SisGenerationController::class, 'show'])->name('residents.sis.generate.show');

    // Vitalwerte (RR, Puls, Temp, BZ, Gewicht, SpO2 ...) je Bewohner
    Route::get('/residents/{resident}/vitals', [VitalSignController::class, 'index'])->name('residents.vitals.index');
    Route::post('/residents/{resident}/vitals', [VitalSignController::class, 'store'])->name('residents.vitals.store');
    Route::delete('/residents/{resident}/vitals/{vitalSign}', [VitalSignController::class, 'destroy'])->name('residents.vitals.destroy');

    // Durchfuehrungsnachweis: geplante Massnahmen + taegliche Quittierung (geplant != geleistet)
    Route::get('/residents/{resident}/care-tasks', [CareTaskController::class, 'index'])->name('residents.care-tasks.index');
    Route::post('/residents/{resident}/care-tasks', [CareTaskController::class, 'store'])->name('residents.care-tasks.store');
    Route::delete('/residents/{resident}/care-tasks/{careTask}', [CareTaskController::class, 'destroy'])->name('residents.care-tasks.destroy');
    Route::post('/residents/{resident}/care-tasks/{careTask}/complete', [CareTaskController::class, 'complete'])->name('residents.care-tasks.complete');
    Route::delete('/residents/{resident}/care-task-completions/{completion}', [CareTaskController::class, 'destroyCompletion'])->name('residents.care-tasks.completions.destroy');

    // Validierte Assessments (Braden, Schmerz/NRS, erweiterbar) je Bewohner
    Route::get('/residents/{resident}/assessments', [AssessmentController::class, 'index'])->name('residents.assessments.index');
    Route::post('/residents/{resident}/assessments', [AssessmentController::class, 'store'])->name('residents.assessments.store');
    Route::delete('/residents/{resident}/assessments/{assessment}', [AssessmentController::class, 'destroy'])->name('residents.assessments.destroy');

    // Wunddokumentation: Wunden + Verlaufseinträge
    Route::get('/residents/{resident}/wounds', [WoundController::class, 'index'])->name('residents.wounds.index');
    Route::post('/residents/{resident}/wounds', [WoundController::class, 'store'])->name('residents.wounds.store');
    Route::patch('/residents/{resident}/wounds/{wound}/status', [WoundController::class, 'updateStatus'])->name('residents.wounds.status');
    Route::delete('/residents/{resident}/wounds/{wound}', [WoundController::class, 'destroy'])->name('residents.wounds.destroy');
    Route::post('/residents/{resident}/wounds/{wound}/assessments', [WoundController::class, 'addAssessment'])->name('residents.wounds.assessments.store');
    Route::delete('/residents/{resident}/wound-assessments/{woundAssessment}', [WoundController::class, 'destroyAssessment'])->name('residents.wounds.assessments.destroy');

    // Qualitätsindikatoren § 113b: Erhebung je Bewohner + Halbjahres-Auswertung
    Route::get('/residents/{resident}/quality', [QualityAssessmentController::class, 'resident'])->name('residents.quality.index');
    Route::post('/residents/{resident}/quality', [QualityAssessmentController::class, 'store'])->name('residents.quality.store');
    Route::get('/quality-indicators', [QualityAssessmentController::class, 'evaluation'])->name('quality.evaluation');

    // Medikamentenmanagement (Medikationsplan + Verabreichungsnachweis/MAR, inkl. BTM)
    Route::get('/residents/{resident}/medications', [MedicationController::class, 'index'])->name('residents.medications.index');
    Route::post('/residents/{resident}/medications', [MedicationController::class, 'store'])->name('residents.medications.store');
    Route::delete('/residents/{resident}/medications/{medication}', [MedicationController::class, 'destroy'])->name('residents.medications.destroy');
    Route::post('/residents/{resident}/medications/{medication}/administer', [MedicationController::class, 'administer'])->name('residents.medications.administer');
    Route::delete('/residents/{resident}/medication-administrations/{administration}', [MedicationController::class, 'destroyAdministration'])->name('residents.medications.administrations.destroy');

    Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');

    // KI-Modelle (nur Admin)
    Route::get('/ai-models', [AiModelController::class, 'index'])->name('ai-models.index');
    Route::post('/ai-models', [AiModelController::class, 'store'])->name('ai-models.store');
    Route::patch('/ai-models/{aiModel}/activate', [AiModelController::class, 'activate'])->name('ai-models.activate');
    Route::post('/ai-models/{aiModel}/test', [AiModelController::class, 'test'])->name('ai-models.test');
    Route::delete('/ai-models/{aiModel}', [AiModelController::class, 'destroy'])->name('ai-models.destroy');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/absence-requests', [AbsenceRequestController::class, 'index'])
        ->name('absence-requests.index');

    Route::post('/absence-requests', [AbsenceRequestController::class, 'store'])
        ->name('absence-requests.store');

    Route::get('/absence-requests/manage', [AbsenceRequestController::class, 'manage'])
        ->name('absence-requests.manage');

    Route::patch('/absence-requests/{absenceRequest}/approve', [AbsenceRequestController::class, 'approve'])
        ->name('absence-requests.approve');

    Route::patch('/absence-requests/{absenceRequest}/reject', [AbsenceRequestController::class, 'reject'])
        ->name('absence-requests.reject');

    Route::get('/roster-blackout-days', [RosterBlackoutDayController::class, 'index'])
        ->name('roster-blackout-days.index');

    Route::post('/roster-blackout-days', [RosterBlackoutDayController::class, 'store'])
        ->name('roster-blackout-days.store');

    Route::get('/shift-wishes', [ShiftWishController::class, 'index'])
        ->name('shift-wishes.index');

    Route::post('/shift-wishes', [ShiftWishController::class, 'store'])
        ->name('shift-wishes.store');

    Route::delete('/shift-wishes/{shiftWish}', [ShiftWishController::class, 'destroy'])
        ->name('shift-wishes.destroy');

    Route::get('/shift-templates', [ShiftTemplateController::class, 'index'])
        ->name('shift-templates.index');

    Route::post('/shift-templates', [ShiftTemplateController::class, 'store'])
        ->name('shift-templates.store');

    // Muss VOR der {shiftTemplate}-Route stehen, sonst greift das Model-Binding.
    Route::patch('/shift-templates/category-staffing', [ShiftTemplateController::class, 'updateCategoryStaffing'])
        ->name('shift-templates.category-staffing.update');

    Route::patch('/shift-templates/{shiftTemplate}', [ShiftTemplateController::class, 'update'])
        ->name('shift-templates.update');

    Route::delete('/shift-templates/{shiftTemplate}', [ShiftTemplateController::class, 'destroy'])
        ->name('shift-templates.destroy');

    Route::get('/my-roster', [MyRosterController::class, 'show'])
        ->name('my-roster.show');

    Route::get('/rosters', [RosterController::class, 'index'])
        ->name('rosters.index');

    Route::post('/rosters', [RosterController::class, 'store'])
        ->name('rosters.store');

    Route::get('/rosters/{roster}', [RosterController::class, 'show'])
        ->name('rosters.show');

    Route::patch('/rosters/{roster}/publish', [RosterController::class, 'publish'])
        ->name('rosters.publish');

    Route::patch('/rosters/{roster}/lock', [RosterController::class, 'lock'])
        ->name('rosters.lock');

    Route::patch('/rosters/{roster}/reopen', [RosterController::class, 'reopen'])
        ->name('rosters.reopen');

    Route::post('/rosters/{roster}/validate', [RosterController::class, 'validateRoster'])
        ->name('rosters.validate');

    Route::post('/rosters/{roster}/generate', [RosterController::class, 'generate'])
        ->name('rosters.generate');

    Route::post('/rosters/{roster}/generate-preview', [RosterController::class, 'generatePreview'])
        ->name('rosters.generate-preview');

    Route::delete('/rosters/{roster}/auto-shifts', [RosterController::class, 'deleteAutoShifts'])
        ->name('rosters.auto-shifts.destroy');

    Route::post('/rosters/{roster}/shifts', [ShiftController::class, 'store'])
        ->name('rosters.shifts.store');

    Route::patch('/rosters/{roster}/shifts/{shift}', [ShiftController::class, 'update'])
        ->name('rosters.shifts.update');

    Route::delete('/rosters/{roster}/shifts/{shift}', [ShiftController::class, 'destroy'])
        ->name('rosters.shifts.destroy');
});

require __DIR__.'/auth.php';
