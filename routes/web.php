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
});



Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('/messages', [MessageController::class, 'getCsvData'])->name('messages.index');
    Route::get('/upload-file', [MessageController::class, "uploadForm"])->name('messages.upload');
    Route::get('/transactions/download/{filename}', [MessageController::class, 'downloadCsv'])->name('transactions.download');
});

// Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
// Route::get('/upload-file', [MessageController::class, "uploadForm"])->name('messages.upload');

Route::group( [], function () {
    Route::post('/upload', [MessageController::class, 'upload'])->name("message.upload");
    Route::post('/process-path', [MessageController::class, 'processFromPath'])->name('messages.store');
    Route::get('/stats', [MessageController::class, 'getStats'])->name("transaction.status");
    Route::get('/messages/{id}', [MessageController::class, 'show'])->name('messages.show');
    Route::post('/cleanup', [MessageController::class, 'cleanup']);
});

require __DIR__.'/auth.php';
