<?php

use App\Http\Controllers\MessageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReceiptMessageController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
})->middleware("device-access");



Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified', "device-access"])->name('dashboard');

Route::middleware(['auth', "device-access"])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    // Route::get('/messages', [MessageController::class, 'getCsvData'])->name('messages.index');
    // Route::get('/upload-file', [MessageController::class, "uploadForm"])->name('messages.upload');
    Route::get('/transactions/download/{filename}', [MessageController::class, 'downloadCsv'])->name('transactions.download');
});

Route::get('/csrf-token', function () {
    return response()->json(['csrf_token' => csrf_token()]);
})->middleware("auth");


Route::middleware(['auth', 'verified', 'device-access'])->group(function () {
    // Main transactions page
    Route::get('/transactions', [MessageController::class, 'index'])
         ->name('transactions.index');

    // Server-side data endpoint for DataTables
    Route::post('/transactions/data', [MessageController::class, 'getData'])
         ->name('transactions.data');

    // Export endpoint
    Route::get('/transactions/export', [MessageController::class, 'export'])
         ->name('transactions.export');
});

require __DIR__.'/auth.php';
