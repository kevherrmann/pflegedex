<?php

use App\Http\Controllers\AbsenceRequestController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\CarePlanController;
use App\Http\Controllers\CarePlanGenerationController;
use App\Http\Controllers\CarePlanPdfController;
use App\Http\Controllers\CareReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\ProfileController;
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

    Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');

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

    Route::patch('/shift-templates/{shiftTemplate}', [ShiftTemplateController::class, 'update'])
        ->name('shift-templates.update');

    Route::patch('/shift-templates/{shiftTemplate}/staffing-rule', [ShiftTemplateController::class, 'updateStaffingRule'])
        ->name('shift-templates.staffing-rule.update');

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
