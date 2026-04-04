<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ControladorUsuarios extends Controller
{
    public function obtenerFormulario(): JsonResponse
    {
        return response()->json([
            'roles' => User::obtenerRolesDisponibles(),
            'sucursales' => User::obtenerSucursalesDisponibles(),
        ]);
    }

    public function listar(): JsonResponse
    {
        $usuarios = User::query()
            ->latest()
            ->get()
            ->map(fn (User $usuario) => $this->formatearUsuario($usuario))
            ->values();

        return response()->json([
            'usuarios' => $usuarios,
        ]);
    }

    public function registrar(Request $solicitud): JsonResponse
    {
        $datos = $solicitud->validate([
            'nombre' => ['required', 'string', 'max:50'],
            'apellido' => ['required', 'string', 'max:50'],
            'documento_identidad' => ['required', 'string', 'max:30', 'unique:usuarios,documento_identidad'],
            'telefono' => ['required', 'string', 'max:30'],
            'direccion' => ['required', 'string', 'max:255'],
            'nombre_usuario' => ['required', 'string', 'max:50', 'unique:usuarios,nombre_usuario'],
            'contrasena' => ['required', 'string', 'min:8', 'max:100'],
            'rol' => ['required', 'string', Rule::in(array_column(User::obtenerRolesDisponibles(), 'value'))],
            'sucursal' => ['nullable', 'string', 'max:120', Rule::in(array_column(User::obtenerSucursalesDisponibles(), 'value'))],
            'foto_factura_luz' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        if ($this->requiereSucursal($datos['rol']) && empty($datos['sucursal'])) {
            return response()->json([
                'message' => 'La sucursal es obligatoria para supervisores y vendedores.',
            ], 422);
        }

        $rutaFacturaLuz = null;

        if ($solicitud->hasFile('foto_factura_luz')) {
            $rutaFacturaLuz = $this->guardarFacturaLuz($solicitud->file('foto_factura_luz'));
        }

        $usuario = User::query()->create([
            'nombre' => $datos['nombre'],
            'apellido' => $datos['apellido'],
            'documento_identidad' => $datos['documento_identidad'],
            'nombre_usuario' => $datos['nombre_usuario'],
            'correo_electronico' => $this->generarCorreoInterno($datos['nombre_usuario']),
            'telefono' => $datos['telefono'],
            'direccion' => $datos['direccion'],
            'contrasena' => $datos['contrasena'],
            'rol' => $datos['rol'],
            'sucursal' => $datos['sucursal'] ?? null,
            'foto_factura_luz' => $rutaFacturaLuz,
            'activo' => true,
        ]);

        return response()->json([
            'message' => 'Usuario registrado correctamente.',
            'usuario' => $this->formatearUsuario($usuario),
        ], 201);
    }

    public function cambiarEstado(Request $solicitud, User $usuario): JsonResponse
    {
        $datos = $solicitud->validate([
            'activo' => ['required', 'boolean'],
        ]);

        /** @var User $usuarioAutenticado */
        $usuarioAutenticado = $solicitud->user();

        if (! $datos['activo'] && $usuarioAutenticado->is($usuario)) {
            return response()->json([
                'message' => 'No puedes desactivar tu propio usuario mientras tienes la sesion iniciada.',
            ], 422);
        }

        $usuario->forceFill([
            'activo' => $datos['activo'],
        ])->save();

        if (! $usuario->activo) {
            $usuario->tokensAcceso()->delete();
        }

        return response()->json([
            'message' => $usuario->activo
                ? 'Usuario activado correctamente.'
                : 'Usuario desactivado correctamente.',
            'usuario' => $this->formatearUsuario($usuario->fresh()),
        ]);
    }

    protected function requiereSucursal(string $rol): bool
    {
        return in_array($rol, [
            User::ROL_VENDEDOR,
            User::ROL_SUPERVISOR_SUCURSAL,
        ], true);
    }

    protected function guardarFacturaLuz(\Illuminate\Http\UploadedFile $archivo): string
    {
        $directorio = public_path('archivos/facturas-luz');

        if (! File::exists($directorio)) {
            File::makeDirectory($directorio, 0755, true);
        }

        $nombreArchivo = now()->format('YmdHis').'_'.Str::random(12).'.'.$archivo->getClientOriginalExtension();
        $archivo->move($directorio, $nombreArchivo);

        return 'archivos/facturas-luz/'.$nombreArchivo;
    }

    protected function generarCorreoInterno(string $nombreUsuario): string
    {
        return mb_strtolower(trim($nombreUsuario)).'@puntotecnologico.local';
    }

    protected function formatearUsuario(User $usuario): array
    {
        return [
            'id' => $usuario->id,
            'nombre' => $usuario->nombre,
            'apellido' => $usuario->apellido,
            'nombre_completo' => trim($usuario->nombre.' '.$usuario->apellido),
            'documento_identidad' => $usuario->documento_identidad,
            'telefono' => $usuario->telefono,
            'direccion' => $usuario->direccion,
            'nombre_usuario' => $usuario->nombre_usuario,
            'rol' => $usuario->rol,
            'rol_etiqueta' => User::obtenerEtiquetaRol($usuario->rol),
            'sucursal' => $usuario->sucursal,
            'foto_factura_luz' => $usuario->foto_factura_luz,
            'url_factura_luz' => $usuario->foto_factura_luz ? url($usuario->foto_factura_luz) : null,
            'activo' => $usuario->activo,
            'creado_en' => optional($usuario->created_at)?->toDateTimeString(),
        ];
    }
}
