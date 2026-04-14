<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\MovimientoCaja;
use App\Models\Producto;
use App\Models\ProductoUnidad;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ControladorVentas extends Controller
{
    public function listar(Request $solicitud): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $solicitud->user();

        if (! in_array($usuario->rol, [User::ROL_VENDEDOR, User::ROL_SUPERVISOR_SUCURSAL, User::ROL_GERENTE], true)) {
            return response()->json([
                'message' => 'Tu rol no tiene permiso para revisar ventas.',
            ], 403);
        }

        $sucursalUsuario = $this->obtenerSucursalDelUsuario($usuario);

        $datos = $solicitud->validate([
            'nro_venta' => ['nullable', 'string', 'max:50'],
            'caja_id' => ['nullable', 'integer'],
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date'],
        ]);

        $consulta = Venta::query()
            ->with(['detalles.producto', 'caja', 'sucursal', 'usuario'])
            ->latest('fecha_venta');

        if ($usuario->rol === User::ROL_VENDEDOR) {
            $consulta->where('usuario_id', $usuario->id);
        } elseif ($usuario->rol === User::ROL_SUPERVISOR_SUCURSAL) {
            if ($sucursalUsuario) {
                $consulta->where('sucursal_id', $sucursalUsuario->id);
            } else {
                $consulta->whereRaw('1 = 0');
            }
        }

        if (filled($datos['nro_venta'] ?? null)) {
            $consulta->where('nro_venta', 'like', '%'.trim((string) $datos['nro_venta']).'%');
        }

        if (filled($datos['caja_id'] ?? null)) {
            $consulta->where('caja_id', (int) $datos['caja_id']);
        }

        if (filled($datos['fecha_desde'] ?? null)) {
            $consulta->whereDate('fecha_venta', '>=', $datos['fecha_desde']);
        }

        if (filled($datos['fecha_hasta'] ?? null)) {
            $consulta->whereDate('fecha_venta', '<=', $datos['fecha_hasta']);
        }

        return response()->json([
            'ventas' => $consulta
                ->limit(100)
                ->get()
                ->map(fn (Venta $venta) => $this->formatearVentaListado($venta))
                ->values(),
        ]);
    }

    public function obtenerFormulario(Request $solicitud): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $solicitud->user();
        $sucursalUsuario = $this->obtenerSucursalDelUsuario($usuario);

        if (! $sucursalUsuario) {
            return response()->json([
                'message' => 'Tu usuario no tiene una sucursal valida asignada para vender.',
            ], 422);
        }

        $cajas = Caja::query()
            ->where('activa', true)
            ->where('sucursal_id', $sucursalUsuario->id)
            ->withSum([
                'movimientos as total_ingresos' => fn ($query) => $query
                    ->where('tipo_movimiento', MovimientoCaja::TIPO_INGRESO),
            ], 'monto')
            ->withSum([
                'movimientos as total_egresos' => fn ($query) => $query
                    ->where('tipo_movimiento', MovimientoCaja::TIPO_EGRESO),
            ], 'monto')
            ->orderBy('nombre')
            ->get()
            ->map(fn (Caja $caja) => [
                'id' => $caja->id,
                'value' => $caja->id,
                'label' => $caja->nombre.' - '.($caja->tipo_moneda === 'usd' ? 'Dolares' : 'Bolivianos'),
                'nombre' => $caja->nombre,
                'tipo_moneda' => $caja->tipo_moneda,
                'metodo_base' => $caja->metodo_base,
                'saldo_actual' => round(((float) ($caja->total_ingresos ?? 0)) - ((float) ($caja->total_egresos ?? 0)), 2),
            ])
            ->values();

        return response()->json([
            'sucursal' => [
                'id' => $sucursalUsuario->id,
                'nombre' => $sucursalUsuario->nombre,
            ],
            'cajas' => $cajas,
        ]);
    }

    public function registrar(Request $solicitud): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $solicitud->user();

        if (! in_array($usuario->rol, [User::ROL_VENDEDOR, User::ROL_SUPERVISOR_SUCURSAL], true)) {
            return response()->json([
                'message' => 'Tu rol no tiene permiso para registrar ventas.',
            ], 403);
        }

        $sucursalUsuario = $this->obtenerSucursalDelUsuario($usuario);

        if (! $sucursalUsuario) {
            return response()->json([
                'message' => 'Tu usuario no tiene una sucursal valida asignada para vender.',
            ], 422);
        }

        $datos = $solicitud->validate([
            'caja_id' => ['required', 'integer', Rule::exists('cajas', 'id')],
            'moneda' => ['required', 'string', Rule::in(['bs', 'usd'])],
            'tipo_cambio' => ['nullable', 'numeric', 'min:0.01'],
            'tipo_item' => ['required', 'string', 'max:100'],
            'cliente_nombre' => ['required', 'string', 'max:150'],
            'cliente_telefono' => ['required', 'string', 'max:40'],
            'cliente_fecha_nacimiento' => ['nullable', 'date'],
            'observaciones' => ['nullable', 'string', 'max:255'],
            'detalles' => ['required', 'array', 'min:1'],
            'detalles.*.producto_id' => ['required', 'integer', Rule::exists('productos', 'id')],
            'detalles.*.cantidad' => ['required', 'integer', 'min:1'],
            'detalles.*.precio_unitario_usd' => ['required', 'numeric', 'min:0'],
            'detalles.*.precio_unitario_bs' => ['required', 'numeric', 'min:0'],
        ]);

        $caja = Caja::query()
            ->where('activa', true)
            ->where('sucursal_id', $sucursalUsuario->id)
            ->find($datos['caja_id']);

        if (! $caja) {
            return response()->json([
                'message' => 'La caja seleccionada no pertenece a tu sucursal o esta inactiva.',
            ], 422);
        }

        if ($caja->tipo_moneda !== $datos['moneda']) {
            return response()->json([
                'message' => 'La moneda de la caja no coincide con la moneda de la venta.',
            ], 422);
        }

        $productos = Producto::query()
            ->with(['marca', 'categoria'])
            ->whereIn('id', collect($datos['detalles'])->pluck('producto_id'))
            ->get()
            ->keyBy('id');

        $tipoCambio = filled($datos['tipo_cambio'] ?? null)
            ? round((float) $datos['tipo_cambio'], 2)
            : null;

        $venta = DB::transaction(function () use ($datos, $usuario, $sucursalUsuario, $productos, $tipoCambio, $caja) {
            $subtotalUsd = 0.0;
            $subtotalBs = 0.0;

            $detallesPreparados = collect($datos['detalles'])->map(function (array $detalle) use ($productos, $sucursalUsuario, &$subtotalUsd, &$subtotalBs) {
                $producto = $productos->get((int) $detalle['producto_id']);

                if (! $producto) {
                    throw ValidationException::withMessages([
                        'detalles' => 'Uno de los productos enviados ya no existe.',
                    ]);
                }

                $cantidad = (int) $detalle['cantidad'];

                $unidadesDisponibles = ProductoUnidad::query()
                    ->where('producto_id', $producto->id)
                    ->where('sucursal_id', $sucursalUsuario->id)
                    ->where('activo', true)
                    ->where('estado', ProductoUnidad::ESTADO_DISPONIBLE)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($unidadesDisponibles->count() < $cantidad) {
                    throw ValidationException::withMessages([
                        'detalles' => 'No hay suficiente stock disponible para completar la venta.',
                    ]);
                }

                $precioUsd = round((float) $detalle['precio_unitario_usd'], 2);
                $precioBs = round((float) $detalle['precio_unitario_bs'], 2);
                $subtotalLineaUsd = round($precioUsd * $cantidad, 2);
                $subtotalLineaBs = round($precioBs * $cantidad, 2);
                $unidadesSeleccionadas = $unidadesDisponibles->take($cantidad)->values();

                $subtotalUsd += $subtotalLineaUsd;
                $subtotalBs += $subtotalLineaBs;

                return [
                    'producto' => $producto,
                    'cantidad' => $cantidad,
                    'precio_lista_usd' => round((float) $producto->precio_unitario_usd, 2),
                    'precio_lista_bs' => round((float) $producto->precio_unitario_bs, 2),
                    'precio_mayorista_usd' => round((float) $producto->precio_mayorista_usd, 2),
                    'precio_mayorista_bs' => round((float) $producto->precio_mayorista_bs, 2),
                    'precio_unitario_usd' => $precioUsd,
                    'precio_unitario_bs' => $precioBs,
                    'subtotal_usd' => $subtotalLineaUsd,
                    'subtotal_bs' => $subtotalLineaBs,
                    'series_snapshot' => $unidadesSeleccionadas
                        ->pluck('numero_serie')
                        ->filter()
                        ->implode(', '),
                    'unidades' => $unidadesSeleccionadas,
                ];
            });

            $venta = Venta::query()->create([
                'nro_venta' => 'VTA-TEMP',
                'sucursal_id' => $sucursalUsuario->id,
                'usuario_id' => $usuario->id,
                'caja_id' => $caja->id,
                'estado' => Venta::ESTADO_CONFIRMADA,
                'moneda' => $datos['moneda'],
                'tipo_cambio' => $tipoCambio,
                'tipo_item' => trim((string) $datos['tipo_item']),
                'cliente_nombre' => trim((string) $datos['cliente_nombre']),
                'cliente_telefono' => trim((string) $datos['cliente_telefono']),
                'cliente_fecha_nacimiento' => $datos['cliente_fecha_nacimiento'] ?? null,
                'subtotal_usd' => round($subtotalUsd, 2),
                'subtotal_bs' => round($subtotalBs, 2),
                'total_usd' => round($subtotalUsd, 2),
                'total_bs' => round($subtotalBs, 2),
                'observaciones' => filled($datos['observaciones'] ?? null) ? trim((string) $datos['observaciones']) : null,
                'fecha_venta' => now(),
            ]);

            $venta->forceFill([
                'nro_venta' => sprintf('VTA-%06d', $venta->id),
            ])->save();

            foreach ($detallesPreparados as $detallePreparado) {
                $venta->detalles()->create([
                    'producto_id' => $detallePreparado['producto']->id,
                    'modelo_snapshot' => $detallePreparado['producto']->modelo,
                    'marca_snapshot' => $detallePreparado['producto']->marca?->nombre,
                    'categoria_snapshot' => $detallePreparado['producto']->categoria?->nombre,
                    'series_snapshot' => $detallePreparado['series_snapshot'] !== '' ? $detallePreparado['series_snapshot'] : null,
                    'cantidad' => $detallePreparado['cantidad'],
                    'precio_lista_usd' => $detallePreparado['precio_lista_usd'],
                    'precio_lista_bs' => $detallePreparado['precio_lista_bs'],
                    'precio_mayorista_usd' => $detallePreparado['precio_mayorista_usd'],
                    'precio_mayorista_bs' => $detallePreparado['precio_mayorista_bs'],
                    'precio_unitario_usd' => $detallePreparado['precio_unitario_usd'],
                    'precio_unitario_bs' => $detallePreparado['precio_unitario_bs'],
                    'subtotal_usd' => $detallePreparado['subtotal_usd'],
                    'subtotal_bs' => $detallePreparado['subtotal_bs'],
                ]);

                foreach ($detallePreparado['unidades'] as $unidad) {
                    $unidad->forceFill([
                        'estado' => ProductoUnidad::ESTADO_VENDIDO,
                        'observaciones' => $this->construirObservacionVenta(
                            $unidad->observaciones,
                            $venta->nro_venta,
                            $usuario
                        ),
                    ])->save();
                }
            }

            $ultimoSaldoCaja = (float) MovimientoCaja::query()
                ->where('caja_id', $caja->id)
                ->latest('fecha_movimiento')
                ->value('saldo_resultante');

            $montoMovimiento = $datos['moneda'] === 'usd'
                ? round($subtotalUsd, 2)
                : round($subtotalBs, 2);

            MovimientoCaja::query()->create([
                'caja_id' => $caja->id,
                'sucursal_id' => $sucursalUsuario->id,
                'usuario_id' => $usuario->id,
                'tipo_movimiento' => MovimientoCaja::TIPO_INGRESO,
                'monto' => $montoMovimiento,
                'moneda' => $datos['moneda'],
                'tipo_cambio' => $tipoCambio,
                'concepto' => 'venta',
                'referencia_tipo' => 'venta',
                'referencia_id' => $venta->id,
                'detalle' => 'Ingreso generado por venta '.$venta->nro_venta,
                'fecha_movimiento' => now(),
                'saldo_resultante' => round($ultimoSaldoCaja + $montoMovimiento, 2),
            ]);

            return $venta->load(['detalles.producto', 'caja', 'sucursal']);
        });

        return response()->json([
            'message' => 'Venta registrada correctamente.',
            'venta' => $this->formatearVenta($venta),
        ], 201);
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

    protected function construirObservacionVenta(?string $observacionActual, string $nroVenta, User $usuario): string
    {
        $lineaBase = sprintf(
            '[%s] Vendida en %s por %s.',
            now()->format('d/m/Y H:i'),
            $nroVenta,
            trim($usuario->nombre.' '.$usuario->apellido)
        );

        return filled($observacionActual)
            ? trim($observacionActual).' | '.$lineaBase
            : $lineaBase;
    }

    protected function formatearVenta(Venta $venta): array
    {
        return [
            'id' => $venta->id,
            'nro_venta' => $venta->nro_venta,
            'sucursal' => $venta->sucursal?->nombre,
            'caja' => $venta->caja?->nombre,
            'moneda' => $venta->moneda,
            'tipo_cambio' => $venta->tipo_cambio,
            'tipo_item' => $venta->tipo_item,
            'cliente_nombre' => $venta->cliente_nombre,
            'cliente_telefono' => $venta->cliente_telefono,
            'cliente_fecha_nacimiento' => optional($venta->cliente_fecha_nacimiento)?->format('Y-m-d'),
            'total_usd' => round((float) $venta->total_usd, 2),
            'total_bs' => round((float) $venta->total_bs, 2),
            'fecha_venta' => optional($venta->fecha_venta)?->format('d/m/Y H:i'),
            'detalles' => $venta->detalles->map(fn ($detalle) => [
                'producto' => $detalle->producto?->modelo,
                'modelo' => $detalle->modelo_snapshot,
                'marca' => $detalle->marca_snapshot,
                'categoria' => $detalle->categoria_snapshot,
                'series' => $detalle->series_snapshot,
                'cantidad' => $detalle->cantidad,
                'precio_venta_usd' => round((float) $detalle->precio_unitario_usd, 2),
                'precio_venta_bs' => round((float) $detalle->precio_unitario_bs, 2),
                'subtotal_usd' => round((float) $detalle->subtotal_usd, 2),
                'subtotal_bs' => round((float) $detalle->subtotal_bs, 2),
            ])->values(),
        ];
    }

    protected function formatearVentaListado(Venta $venta): array
    {
        return [
            'id' => $venta->id,
            'nro_venta' => $venta->nro_venta,
            'sucursal' => $venta->sucursal?->nombre,
            'caja' => $venta->caja?->nombre,
            'usuario' => $venta->usuario
                ? trim($venta->usuario->nombre.' '.$venta->usuario->apellido)
                : null,
            'cliente_nombre' => $venta->cliente_nombre,
            'tipo_item' => $venta->tipo_item,
            'moneda' => $venta->moneda,
            'tipo_cambio' => $venta->tipo_cambio,
            'total_usd' => round((float) $venta->total_usd, 2),
            'total_bs' => round((float) $venta->total_bs, 2),
            'fecha_venta' => optional($venta->fecha_venta)?->format('d/m/Y H:i'),
            'cantidad_items' => $venta->detalles->sum('cantidad'),
        ];
    }
}
