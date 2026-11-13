<?php

declare(strict_types=1);

use FancySign\Laravel\Http\PublicController;
use FancySign\Laravel\Http\SenderController;
use FancySign\Laravel\Http\SignerController;
use Illuminate\Support\Facades\Route;

/*
 * JSON and PDF endpoints only. This package registers no page: the signing
 * page and the sender's screens are the host's own (fancy-sign-ui), linked
 * from the host's navigation.
 */
$prefix = trim((string) config('fancy-sign.prefix', 'fancy-sign'), '/');

Route::prefix($prefix)->name('fancy-sign.')->group(function (): void {
    Route::middleware(config('fancy-sign.sender_middleware', []))->group(function (): void {
        Route::get('envelopes', [SenderController::class, 'index'])->name('envelopes.index');
        Route::post('envelopes', [SenderController::class, 'store'])->name('envelopes.store');
        Route::get('envelopes/{id}', [SenderController::class, 'show'])->name('envelopes.show');
        Route::put('envelopes/{id}', [SenderController::class, 'update'])->name('envelopes.update');
        Route::post('envelopes/{id}/send', [SenderController::class, 'send'])->name('envelopes.send');
        Route::post('envelopes/{id}/void', [SenderController::class, 'void'])->name('envelopes.void');
        Route::post('envelopes/{id}/remind', [SenderController::class, 'remind'])->name('envelopes.remind');
        Route::get('envelopes/{id}/events', [SenderController::class, 'events'])->name('envelopes.events');
        Route::get('envelopes/{id}/certificate', [SenderController::class, 'certificate'])->name('envelopes.certificate');
        Route::get('envelopes/{id}/document', [SenderController::class, 'document'])->name('envelopes.document');
    });

    Route::middleware(config('fancy-sign.signer_middleware', []))->prefix('s/{token}')->name('signer.')->group(function (): void {
        Route::post('otp', [SignerController::class, 'otp'])->name('otp');
        Route::post('otp/verify', [SignerController::class, 'verify'])->name('verify');
        Route::get('envelope', [SignerController::class, 'envelope'])->name('envelope');
        Route::post('consent', [SignerController::class, 'consent'])->name('consent');
        Route::get('document', [SignerController::class, 'document'])->name('document');
        Route::post('events', [SignerController::class, 'events'])->name('events');
        Route::post('sign', [SignerController::class, 'sign'])->name('sign');
        Route::post('decline', [SignerController::class, 'decline'])->name('decline');
    });

    Route::middleware(config('fancy-sign.public_middleware', []))->group(function (): void {
        Route::post('verify', [PublicController::class, 'verify'])->name('verify');
        Route::get('public-key', [PublicController::class, 'publicKey'])->name('public-key');
    });
});
