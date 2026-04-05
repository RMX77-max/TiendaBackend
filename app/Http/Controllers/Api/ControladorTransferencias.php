<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use App\Models\ProductoUnidad;
use App\Models\SolicitudTransferencia;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ControladorTransferencias extends Controller
{
    public function listarSolicitudes(Request $solicitud): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $solicitud->user();
        $sucursalUsuario = $this->obtenerSucursalDelUsuario($usuario);

        $misSolicitudes = collect();
        $solicitudesPorRevisar = collect();
        $historialRecibidas = collect();

        if ($sucursalUsuario) {
            $misSolicitudes = SolicitudTransferencia::query()
                ->with(['producto', 'usuarioSolicitante', 'sucursalSolicitante', 'sucursalOrigen', 'usuarioRevisor'])
                ->where('sucursal_solicitante_id', $sucursalUsuario->id)
                ->latest()
                ->get()
                ->map(fn (SolicitudTransferencia $item) => $this->formatearSolicitud($item));

            if ($usuario->rol === User::ROL_SUPERVISOR_SUCURSAL) {
                $solicitudesPorRevisar = SolicitudTransferencia::query()
                    ->with(['producto', 'usuarioSolicitante', 'sucursalSolicitante', 'sucursalOrigen', 'usuarioRevisor'])
                    ->where('sucursal_origen_id', $sucursalUsuario->id)
                    ->where('estado', SolicitudTransferencia::ESTADO_PENDIENTE)
                    ->latest()
                    ->get()
                    ->map(fn (SolicitudTransferencia $item) => $this->formatearSolicitud($item));

                $historialRecibidas = SolicitudTransferencia::query()
                    ->with(['producto', 'usuarioSolicitante', 'sucursalSolicitante', 'sucursalOrigen', 'usuarioRevisor'])
                    ->where('sucursal_origen_id', $sucursalUsuario->id)
                    ->latest()
                    ->get()
                    ->map(fn (SolicitudTransferencia $item) => $this->formatearSolicitud($item));
            }
        }

        return response()->json([
            'mis_solicitudes' => $misSolicitudes->values(),
            'solicitudes_por_revisar' => $solicitudesPorRevisar->values(),
            'historial_recibidas' => $historialRecibidas->values(),
            'cantidad_pendientes_revision' => $solicitudesPorRevisar->count(),
            'cantidad_respuestas' => $misSolicitudes
                ->whereIn('estado', [
                    SolicitudTransferencia::ESTADO_APROBADA,
                    SolicitudTransferencia::ESTADO_APROBADA_PARCIAL,
                    SolicitudTransferencia::ESTADO_RECHAZADA,
                ])
                ->count(),
        ]);
    }

    public function registrarSolicitud(Request $solicitud): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $solicitud->user();

        if (! in_array($usuario->rol, [User::ROL_SUPERVISOR_SUCURSAL, User::ROL_VENDEDOR], true)) {
            return response()->json([
                'message' => 'Tu rol no tiene permiso para registrar solicitudes de transferencia.',
            ], 403);
        }

        $sucursalSolicitante = $this->obtenerSucursalDelUsuario($usuario);

        if (! $sucursalSolicitante) {
            return response()->json([
                'message' => 'El usuario autenticado no tiene una sucursal valida asignada.',
            ], 422);
        }

        $datos = $solicitud->validate([
            'producto_id' => ['required', 'integer', Rule::exists('productos', 'id')],
            'sucursal_origen_id' => ['required', 'integer', Rule::exists('sucursales', 'id')],
            'cantidad_solicitada' => ['required', 'integer', 'min:1'],
            'detalle_solicitud' => ['nullable', 'string', 'max:1000'],
        ]);

        if ((int) $datos['sucursal_origen_id'] === (int) $sucursalSolicitante->id) {
            return response()->json([
                'message' => 'No puedes solicitar transferencia a tu misma sucursal.',
            ], 422);
        }

        $producto = Producto::query()->findOrFail($datos['producto_id']);
        $sucursalOrigen = Sucursal::query()->where('activa', true)->find($datos['sucursal_origen_id']);

        if (! $sucursalOrigen) {
            return response()->json([
                'message' => 'La sucursal proveedora no es valida o se encuentra inactiva.',
            ], 422);
        }

        $cantidadDisponible = $this->contarUnidadesDisponibles($producto->id, $sucursalOrigen->id);

        if ($cantidadDisponible <= 0) {
            return response()->json([
                'message' => 'La sucursal seleccionada ya no tiene unidades disponibles de este producto.',
            ], 422);
        }

        $solicitudTransferencia = SolicitudTransferencia::query()->create([
            'producto_id' => $producto->id,
            'usuario_solicitante_id' => $usuario->id,
            'sucursal_solicitante_id' => $sucursalSolicitante->id,
            'sucursal_origen_id' => $sucursalOrigen->id,
            'cantidad_solicitada' => $datos['cantidad_solicitada'],
            'detalle_solicitud' => $datos['detalle_solicitud'] ?? null,
            'estado' => SolicitudTransferencia::ESTADO_PENDIENTE,
        ])->load(['producto', 'usuarioSolicitante', 'sucursalSolicitante', 'sucursalOrigen', 'usuarioRevisor']);

        return response()->json([
            'message' => 'Solicitud de transferencia registrada correctamente.',
            'solicitud' => $this->formatearSolicitud($solicitudTransferencia),
        ], 201);
    }

    public function responderSolicitud(Request $solicitud, SolicitudTransferencia $solicitudTransferencia): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $solicitud->user();

        if ($usuario->rol !== User::ROL_SUPERVISOR_SUCURSAL) {
            return response()->json([
                'message' => 'Solo los supervisores pueden responder solicitudes de transferencia.',
            ], 403);
        }

        $sucursalUsuario = $this->obtenerSucursalDelUsuario($usuario);

        if (! $sucursalUsuario || (int) $sucursalUsuario->id !== (int) $solicitudTransferencia->sucursal_origen_id) {
            return response()->json([
                'message' => 'No tienes permiso para responder esta solicitud.',
            ], 403);
        }

        if ($solicitudTransferencia->estado !== SolicitudTransferencia::ESTADO_PENDIENTE) {
            return response()->json([
                'message' => 'La solicitud seleccionada ya fue atendida.',
            ], 422);
        }

        $datos = $solicitud->validate([
            'accion' => ['required', 'string', Rule::in(['aceptar', 'rechazar'])],
            'cantidad_aprobada' => ['nullable', 'integer', 'min:0'],
            'detalle_respuesta' => ['nullable', 'string', 'max:1000'],
        ]);

        $solicitudTransferencia->load(['producto', 'usuarioSolicitante', 'sucursalSolicitante', 'sucursalOrigen']);

        if ($datos['accion'] === 'rechazar') {
            $solicitudTransferencia->forceFill([
                'estado' => SolicitudTransferencia::ESTADO_RECHAZADA,
                'cantidad_aprobada' => 0,
                'detalle_respuesta' => filled($datos['detalle_respuesta'] ?? null)
                    ? trim((string) $datos['detalle_respuesta'])
                    : null,
                'usuario_revisor_id' => $usuario->id,
                'fecha_respuesta' => now(),
            ])->save();

            return response()->json([
                'message' => 'Solicitud rechazada correctamente.',
                'solicitud' => $this->formatearSolicitud($solicitudTransferencia->fresh([
                    'producto', 'usuarioSolicitante', 'sucursalSolicitante', 'sucursalOrigen', 'usuarioRevisor',
                ])),
            ]);
        }

        $cantidadAprobada = (int) ($datos['cantidad_aprobada'] ?? 0);

        if ($cantidadAprobada <= 0) {
            return response()->json([
                'message' => 'La cantidad aprobada debe ser mayor a cero.',
            ], 422);
        }

        DB::transaction(function () use ($solicitudTransferencia, $usuario, $cantidadAprobada, $datos) {
            $unidadesDisponibles = ProductoUnidad::query()
                ->where('producto_id', $solicitudTransferencia->producto_id)
                ->where('sucursal_id', $solicitudTransferencia->sucursal_origen_id)
                ->where('activo', true)
                ->where('estado', ProductoUnidad::ESTADO_DISPONIBLE)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($unidadesDisponibles->count() < $cantidadAprobada) {
                throw ValidationException::withMessages([
                    'cantidad_aprobada' => 'No existe suficiente stock disponible para aprobar esta transferencia.',
                ]);
            }

            $unidadesATransferir = $unidadesDisponibles->take($cantidadAprobada);
            $detalleRespuesta = trim((string) ($datos['detalle_respuesta'] ?? ''));
            $estadoFinal = $cantidadAprobada < (int) $solicitudTransferencia->cantidad_solicitada
                ? SolicitudTransferencia::ESTADO_APROBADA_PARCIAL
                : SolicitudTransferencia::ESTADO_APROBADA;

            foreach ($unidadesATransferir as $unidad) {
                $unidad->forceFill([
                    'sucursal_id' => $solicitudTransferencia->sucursal_solicitante_id,
                    'observaciones' => $this->construirObservacionTransferencia(
                        $unidad->observaciones,
                        $solicitudTransferencia,
                        $usuario,
                        $detalleRespuesta
                    ),
                ])->save();
            }

            $solicitudTransferencia->forceFill([
                'estado' => $estadoFinal,
                'cantidad_aprobada' => $cantidadAprobada,
                'detalle_respuesta' => $detalleRespuesta !== '' ? $detalleRespuesta : null,
                'usuario_revisor_id' => $usuario->id,
                'fecha_respuesta' => now(),
            ])->save();
        });

        return response()->json([
            'message' => 'Solicitud atendida correctamente.',
            'solicitud' => $this->formatearSolicitud($solicitudTransferencia->fresh([
                'producto', 'usuarioSolicitante', 'sucursalSolicitante', 'sucursalOrigen', 'usuarioRevisor',
            ])),
        ]);
    }

    protected function obtenerSucursalDelUsuario(User $usuario): ?Sucursal
    {
        if (blank($usuario->sucursal)) {
            return null;
        }

        return Sucursal::query()
            ->where('activa', true)
            ->whereRaw('LOWER(nombre) = ?', [mb_strtolower(trim((string) $usuario->sucursal))])
            ->first();
    }

    protected function contarUnidadesDisponibles(int $productoId, int $sucursalId): int
    {
        return ProductoUnidad::query()
            ->where('producto_id', $productoId)
            ->where('sucursal_id', $sucursalId)
            ->where('activo', true)
            ->where('estado', ProductoUnidad::ESTADO_DISPONIBLE)
            ->count();
    }

    protected function construirObservacionTransferencia(
        ?string $observacionActual,
        SolicitudTransferencia $solicitudTransferencia,
        User $usuarioRevisor,
        string $detalleRespuesta,
    ): string {
        $lineaBase = sprintf(
            '[%s] Transferida de %s a %s. Solicitud #%d aprobada por %s.',
            now()->format('d/m/Y H:i'),
            $solicitudTransferencia->sucursalOrigen?->nombre,
            $solicitudTransferencia->sucursalSolicitante?->nombre,
            $solicitudTransferencia->id,
            trim($usuarioRevisor->nombre.' '.$usuarioRevisor->apellido)
        );

        if ($detalleRespuesta !== '') {
            $lineaBase .= ' Detalle: '.$detalleRespuesta;
        }

        return filled($observacionActual)
            ? trim($observacionActual).' | '.$lineaBase
            : $lineaBase;
    }

    protected function formatearSolicitud(SolicitudTransferencia $solicitudTransferencia): array
    {
        return [
            'id' => $solicitudTransferencia->id,
            'producto_id' => $solicitudTransferencia->producto_id,
            'producto_modelo' => $solicitudTransferencia->producto?->modelo,
            'usuario_solicitante_id' => $solicitudTransferencia->usuario_solicitante_id,
            'usuario_solicitante' => $solicitudTransferencia->usuarioSolicitante
                ? trim($solicitudTransferencia->usuarioSolicitante->nombre.' '.$solicitudTransferencia->usuarioSolicitante->apellido)
                : null,
            'sucursal_solicitante_id' => $solicitudTransferencia->sucursal_solicitante_id,
            'sucursal_solicitante' => $solicitudTransferencia->sucursalSolicitante?->nombre,
            'sucursal_origen_id' => $solicitudTransferencia->sucursal_origen_id,
            'sucursal_origen' => $solicitudTransferencia->sucursalOrigen?->nombre,
            'cantidad_solicitada' => $solicitudTransferencia->cantidad_solicitada,
            'cantidad_aprobada' => $solicitudTransferencia->cantidad_aprobada,
            'detalle_solicitud' => $solicitudTransferencia->detalle_solicitud,
            'detalle_respuesta' => $solicitudTransferencia->detalle_respuesta,
            'estado' => $solicitudTransferencia->estado,
            'usuario_revisor_id' => $solicitudTransferencia->usuario_revisor_id,
            'usuario_revisor' => $solicitudTransferencia->usuarioRevisor
                ? trim($solicitudTransferencia->usuarioRevisor->nombre.' '.$solicitudTransferencia->usuarioRevisor->apellido)
                : null,
            'fecha_solicitud' => optional($solicitudTransferencia->created_at)?->format('d/m/Y - H:i'),
            'fecha_solicitud_iso' => optional($solicitudTransferencia->created_at)?->toIso8601String(),
            'fecha_respuesta' => optional($solicitudTransferencia->fecha_respuesta)?->format('d/m/Y - H:i'),
            'fecha_respuesta_iso' => optional($solicitudTransferencia->fecha_respuesta)?->toIso8601String(),
        ];
    }
}
