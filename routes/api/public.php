<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProtocolAttachmentController;
use App\Http\Controllers\Api\VeterinarianInvitationController;
use Illuminate\Support\Facades\Route;

/*
 * Public routes: no `auth:sanctum`. Each one carries its own credential and the server
 * validates it on every call.
 */

// SEC-04: cap credential-guessing (5 attempts/min per email+IP) via the 'login' limiter.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// ADR-33: public on purpose, like the portal links. The invitation IS the credential.
Route::controller(VeterinarianInvitationController::class)->prefix('invitations')->group(function (): void {
    Route::get('/{token}', 'show');
    Route::post('/{token}/accept', 'accept');
});

/*
 * Sanitary evidence download (ADR-6). The URL is signed and short-lived, but the controller
 * still requires membership in the company: a leaked signed URL must not be enough to read
 * documentary evidence.
 */
Route::get('/protocol-attachments/{attachment}/download', ProtocolAttachmentController::class)
    ->middleware('signed')
    ->name('protocol-attachments.download');
