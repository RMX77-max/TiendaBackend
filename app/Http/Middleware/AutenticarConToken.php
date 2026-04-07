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
        $tokenPlano = $this->obtenerTokenDesdeSolicitud($solicitud);

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

    protected function obtenerTokenDesdeSolicitud(Request $solicitud): ?string
    {
        $candidatos = [
            $solicitud->bearerToken(),
            $solicitud->header('X-Access-Token'),
            $solicitud->header('Authorization'),
            $solicitud->server('HTTP_AUTHORIZATION'),
            $solicitud->server('REDIRECT_HTTP_AUTHORIZATION'),
        ];

        if (function_exists('getallheaders')) {
            $cabeceras = getallheaders();
            $candidatos[] = $cabeceras['Authorization'] ?? null;
            $candidatos[] = $cabeceras['authorization'] ?? null;
            $candidatos[] = $cabeceras['X-Access-Token'] ?? null;
            $candidatos[] = $cabeceras['x-access-token'] ?? null;
        }

        foreach ($candidatos as $candidato) {
            $token = $this->normalizarToken($candidato);

            if ($token !== null) {
                return $token;
            }
        }

        return null;
    }

    protected function normalizarToken(?string $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $valor = trim($valor);

        if ($valor === '') {
            return null;
        }

        if (str_starts_with(strtolower($valor), 'bearer ')) {
            $valor = trim(substr($valor, 7));
        }

        return $valor !== '' ? $valor : null;
    }
}
