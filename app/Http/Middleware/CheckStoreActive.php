<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Regla 2 del Gestor de Oportunidades: si una agencia deja de trabajar con
 * BoTravel (Store::status = 'inactive'), sus usuarios pierden acceso al
 * panel. El superadmin nunca se bloquea, sin importar el store al que esté
 * atado, y el status 'demo' NO cuenta como inactivo (sigue con acceso).
 */
class CheckStoreActive
{
    public function handle(Request $request, Closure $next)
    {
        if (app()->runningInConsole()) {
            return $next($request);
        }

        if ($request->is('api/whatsapp/webhook', 'api/whatsapp/webhook/*')) {
            return $next($request);
        }

        if (Auth::check()) {
            $user = Auth::user();

            if (!$user->is_super_admin && $user->store && $user->store->status === 'inactive') {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                abort(403, 'Esta cuenta fue suspendida. Contacta al administrador de la plataforma.');
            }
        }

        return $next($request);
    }
}
