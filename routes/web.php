<?php

use Illuminate\Support\Facades\Route;

// Filament handles the root path, authentication, and dashboard
// No need to define routes here - Filament will intercept and handle them

// Healthcheck de infraestructura (Docker/nginx) — sin auth, sin lógica de
// dominio, solo confirma que la app responde de punta a punta (Parte XV).
Route::get('/health', fn () => response()->json(['status' => 'ok']));
