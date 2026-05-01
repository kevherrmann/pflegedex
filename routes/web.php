<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ResidentController;
use App\Models\Resident;
use App\Support\BrandPalette;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'branding' => BrandPalette::inertiaPayload(),
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    $user = request()->user();
    $location = $user?->location;

    return Inertia::render('Dashboard', [
        'stats' => [
            'locationName' => $location?->name ?? 'kein Wohnbereich zugeordnet',
            'residentsActive' => $location
                ? Resident::query()->forLocation($location)->active()->count()
                : 0,
            'rolesPrepared' => 3,
            'locationsPrepared' => 1,
        ],
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/residents', [ResidentController::class, 'index'])->name('residents.index');
    Route::get('/residents/create', [ResidentController::class, 'create'])->name('residents.create');
    Route::post('/residents', [ResidentController::class, 'store'])->name('residents.store');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
