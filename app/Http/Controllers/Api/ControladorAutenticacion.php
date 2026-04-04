<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TokenAcceso;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ControladorAutenticacion extends Controller
{
    public function iniciarSesion(Request $solicitud): JsonResponse
    {
        $credenciales = $solicitud->validate([
            'nombre_usuario' => ['required', 'string'],
            'contrasena' => ['required', 'string'],
            'nombre_dispositivo' => ['nullable', 'string', 'max:100'],
        ]);

        $usuario = User::query()
            ->where('nombre_usuario', mb_strtolower(trim($credenciales['nombre_usuario'])))
            ->first();

        if (! $usuario || ! Hash::check($credenciales['contrasena'], $usuario->contrasena)) {
            return response()->json([
                'message' => 'Credenciales incorrectas.',
            ], 422);
        }

        if (! $usuario->activo) {
            return response()->json([
                'message' => 'El usuario se encuentra inactivo.',
            ], 403);
        }

        $tokenPlano = Str::random(80);
        $tokenAcceso = TokenAcceso::query()->create([
            'user_id' => $usuario->id,
            'nombre' => $credenciales['nombre_dispositivo'] ?? 'quasar',
            'token' => hash('sha256', $tokenPlano),
            'ultimo_uso_en' => now(),
            'expira_en' => now()->addDays(7),
        ]);

        return response()->json([
            'message' => 'Inicio de sesion correcto.',
            'token' => $tokenPlano,
            'token_type' => 'Bearer',
            'expira_en' => $tokenAcceso->expira_en,
            'usuario' => $this->formatearUsuario($usuario),
        ]);
    }

    public function perfil(Request $solicitud): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $solicitud->user();

        return response()->json([
            'usuario' => $this->formatearUsuario($usuario),
        ]);
    }

    public function cerrarSesion(Request $solicitud): JsonResponse
    {
        /** @var TokenAcceso|null $tokenAcceso */
        $tokenAcceso = $solicitud->attributes->get('token_acceso');

        if ($tokenAcceso) {
            $tokenAcceso->delete();
        }

        return response()->json([
            'message' => 'Sesion cerrada correctamente.',
        ]);
    }

    protected function formatearUsuario(User $usuario): array
    {
        return [
            'id' => $usuario->id,
            'nombre' => $usuario->nombre,
            'apellido' => $usuario->apellido,
            'nombre_completo' => trim($usuario->nombre.' '.$usuario->apellido),
            'nombre_usuario' => $usuario->nombre_usuario,
            'rol' => $usuario->rol,
            'rol_etiqueta' => User::obtenerEtiquetaRol($usuario->rol),
            'sucursal' => $usuario->sucursal,
            'activo' => $usuario->activo,
        ];
    }
}
