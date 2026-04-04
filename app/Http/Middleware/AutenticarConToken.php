<?php

namespace App\Http\Middleware;

use App\Models\TokenAcceso;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AutenticarConToken
{
    public function handle(Request $solicitud, Closure $siguiente): Response
    {
        $tokenPlano = $solicitud->bearerToken();

        if (! $tokenPlano) {
            return new JsonResponse([
                'message' => 'Token de acceso no proporcionado.',
            ], 401);
        }

        $tokenAcceso = TokenAcceso::query()
            ->with('usuario')
            ->where('token', hash('sha256', $tokenPlano))
            ->first();

        if (! $tokenAcceso || ! $tokenAcceso->usuario) {
            return new JsonResponse([
                'message' => 'Token de acceso invalido.',
            ], 401);
        }

        if ($tokenAcceso->expira_en && $tokenAcceso->expira_en->isPast()) {
            $tokenAcceso->delete();

            return new JsonResponse([
                'message' => 'El token de acceso ha expirado.',
            ], 401);
        }

        if (! $tokenAcceso->usuario->activo) {
            return new JsonResponse([
                'message' => 'El usuario se encuentra inactivo.',
            ], 403);
        }

        $tokenAcceso->forceFill([
            'ultimo_uso_en' => now(),
        ])->save();

        $solicitud->setUserResolver(fn () => $tokenAcceso->usuario);
        $solicitud->attributes->set('token_acceso', $tokenAcceso);

        return $siguiente($solicitud);
    }
}
