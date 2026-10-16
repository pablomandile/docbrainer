<?php

use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\ChatStreamController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\Settings\TextSizeController;
use App\Http\Controllers\SummaryController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('projects.index')
        : redirect()->route('login');
})->name('home');

// Tamaño de letra: con o sin sesión (sin sesión queda solo la cookie)
Route::put('text-size', [TextSizeController::class, 'update'])->name('text-size.update');

// Ingreso con Google. `guest`: con la sesión abierta, rehacer el flujo solo
// puede terminar cambiando de cuenta sin querer.
Route::middleware('guest')->group(function () {
    Route::get('auth/google/redirect', [GoogleController::class, 'redirect'])->name('google.redirect');
    Route::get('auth/google/callback', [GoogleController::class, 'callback'])->name('google.callback');
});

// Cuentas registradas que esperan que un admin las active
Route::middleware('auth')->get('pendiente', function () {
    return request()->user()->is_active
        ? redirect()->route('projects.index')
        : inertia('auth/PendingApproval');
})->name('pending-approval');

Route::middleware(['auth', 'verified', 'active', 'admin'])
    ->prefix('admin')->name('admin.')->group(function () {
        Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
        Route::patch('users/{user}', [AdminUserController::class, 'update'])->name('users.update');
    });

Route::middleware(['auth', 'verified', 'active'])->group(function () {
    Route::redirect('dashboard', '/projects')->name('dashboard');

    Route::inertia('como-funciona', 'ComoFunciona')->name('how-it-works');

    Route::resource('projects', ProjectController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy']);

    Route::prefix('projects/{project}')->name('projects.')->group(function () {
        Route::get('documents/status', [DocumentController::class, 'status'])->name('documents.status');
        Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
        Route::post('documents/{document}/retry', [DocumentController::class, 'retry'])->name('documents.retry');
        Route::post('documents/{document}/reindex', [DocumentController::class, 'reindex'])->name('documents.reindex');
        Route::get('documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
        Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

        Route::post('chat', ChatStreamController::class)->name('chat.stream');
        Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
        Route::delete('conversations/{conversation}', [ConversationController::class, 'destroy'])->name('conversations.destroy');

        Route::get('summaries/status', [SummaryController::class, 'status'])->name('summaries.status');
        Route::post('summaries', [SummaryController::class, 'store'])->name('summaries.store');
        Route::delete('summaries/{summary}', [SummaryController::class, 'destroy'])->name('summaries.destroy');
    });
});

require __DIR__.'/settings.php';
