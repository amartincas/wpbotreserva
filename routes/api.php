<?php

use App\Http\Controllers\InboundWhatsAppWebhookController;
use App\Http\Controllers\WhatsAppController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// ── Public webhook routes (no auth — Meta calls these directly) ─────────────
Route::prefix('whatsapp')->group(function () {
    Route::get('/webhook', [WhatsAppController::class, 'verify']);
    Route::post('/webhook', [WhatsAppController::class, 'handle']);
});

// ── WpbotReserva: webhook propio, separado del bot de turismo (Hito 7) ──────
Route::prefix('wpbotreserva/whatsapp')->group(function () {
    Route::get('/webhook', [InboundWhatsAppWebhookController::class, 'verify']);
    Route::post('/webhook', [InboundWhatsAppWebhookController::class, 'handle']);
});
