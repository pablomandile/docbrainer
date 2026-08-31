<?php

use App\Http\Controllers\ChatStreamController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SummaryController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('projects.index')
        : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
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
