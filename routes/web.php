<?php

use App\Http\Controllers\CareReportController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ResidentController;
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

Route::get('/dashboard', function () {
    $user = request()->user();
    $locations = $user?->accessibleLocations() ?? collect();
    $locationName = match ($locations->count()) {
        0 => 'kein Wohnbereich zugeordnet',
        1 => $locations->first()->name,
        default => $locations->count().' Wohnbereiche',
    };

    return Inertia::render('Dashboard', [
        'stats' => [
            'locationName' => $locationName,
            'residentsActive' => $locations->isNotEmpty()
                ? Resident::query()->whereIn('location_id', $locations->pluck('id'))->active()->count()
                : 0,
            'rolesPrepared' => 3,
            'locationsPrepared' => $locations->count(),
        ],
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

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

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
