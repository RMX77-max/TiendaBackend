<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ActualizarCompraRequest;
use App\Http\Requests\RegistrarCompraRequest;
use App\Http\Requests\RegistrarProveedorRequest;
use App\Models\Compra;
use App\Models\CompraDetalle;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Compras\RecalculadoraCompraService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ControladorCompras extends Controller
{
    public function __construct(
        protected RecalculadoraCompraService $recalculadoraCompraService
    ) {
    }

    public function obtenerFormulario(Request $solicitud): JsonResponse
    {
        if ($respuestaAutorizacion = $this->autorizarGestionCompras($solicitud)) {
            return $respuestaAutorizacion;
        }

        return response()->json([
            'tipos_compra' => collect(Compra::obtenerTiposCompraDisponibles())
                ->map(fn (string $tipo) => [
                    'value' => $tipo,
                    'label' => $this->etiquetaTipoCompra($tipo),
                ])
                ->values()
                ->all(),
            'proveedores' => $this->formatearProveedores(
                Proveedor::query()->where('activo', true)->orderBy('nombre')->get()
            ),
            'sucursales' => $this->formatearSucursales(
                Sucursal::query()->where('activa', true)->orderBy('nombre')->get()
            ),
            'productos' => $this->formatearProductosAutocompletado(
                Producto::query()
                    ->where('activo', true)
                    ->with(['marca', 'categoria'])
                    ->orderBy('modelo')
                    ->get()
            ),
        ]);
    }

    public function listar(Request $solicitud): JsonResponse
    {
        if ($respuestaAutorizacion = $this->autorizarGestionCompras($solicitud)) {
            return $respuestaAutorizacion;
        }

        $consulta = Compra::query()
            ->with(['proveedor', 'detalles'])
            ->orderByDesc('fecha_pedido')
            ->orderByDesc('id');

        if ($solicitud->filled('proveedor_id')) {
            $consulta->where('proveedor_id', $solicitud->integer('proveedor_id'));
        }

        if ($solicitud->filled('estado')) {
            $consulta->where('estado', $solicitud->string('estado')->toString());
        }

        if ($solicitud->filled('tipo_compra')) {
            $consulta->where('tipo_compra', $solicitud->string('tipo_compra')->toString());
        }

        if ($solicitud->filled('nro_pedido')) {
            $consulta->where('nro_pedido', 'like', '%'.$solicitud->string('nro_pedido')->toString().'%');
        }

        if ($solicitud->filled('fecha_desde')) {
            $consulta->whereDate('fecha_pedido', '>=', $solicitud->date('fecha_desde'));
        }

        if ($solicitud->filled('fecha_hasta')) {
            $consulta->whereDate('fecha_pedido', '<=', $solicitud->date('fecha_hasta'));
        }

        return response()->json([
            'compras' => $consulta
                ->get()
                ->map(fn (Compra $compra) => $this->formatearCompraResumen($compra))
                ->values(),
        ]);
    }

    public function registrar(RegistrarCompraRequest $solicitud): JsonResponse
    {
        if ($respuestaAutorizacion = $this->autorizarGestionCompras($solicitud)) {
            return $respuestaAutorizacion;
        }

        $datos = $solicitud->validated();
        $detallesNormalizados = $this->recalculadoraCompraService
            ->normalizarDetalles($datos['detalles'], (float) $datos['tipo_cambio_general']);
        $abonosNormalizados = $this->recalculadoraCompraService
            ->normalizarAbonos($datos['abonos'] ?? [], (float) $datos['tipo_cambio_general']);

        foreach ($detallesNormalizados as $detalle) {
            if ($detalle['detalle_texto'] === '') {
                return response()->json([
                    'message' => 'Cada fila del detalle debe tener un producto seleccionado o un detalle libre.',
                ], 422);
            }
        }

        if ($datos['tipo_compra'] === Compra::TIPO_CREDITO && $abonosNormalizados->isNotEmpty()) {
            return response()->json([
                'message' => 'Las compras a credito no admiten abonos directos. Primero deben manejar cuotas.',
            ], 422);
        }

        $totalAbonosUsd = round((float) $abonosNormalizados->sum('abono_usd'), 4);
        $totalProductosUsd = round((float) $detallesNormalizados->sum('subtotal_usd'), 4);

        if ($totalAbonosUsd > $totalProductosUsd + 0.0001) {
            return response()->json([
                'message' => 'La suma de abonos no puede superar el costo total del pedido.',
            ], 422);
        }

        $compra = DB::transaction(function () use ($datos, $detallesNormalizados, $abonosNormalizados) {
            $compra = Compra::query()->create([
                'proveedor_id' => $datos['proveedor_id'],
                'fecha_pedido' => $datos['fecha_pedido'],
                'tipo_compra' => $datos['tipo_compra'],
                'nro_pedido' => 'TMP-'.Str::upper(Str::random(12)),
                'tipo_cambio_general' => $datos['tipo_cambio_general'],
                'tiempo_entrega_dias' => $datos['tiempo_entrega_dias'],
                'observaciones' => $datos['observaciones'] ?? null,
                'estado' => Compra::ESTADO_BORRADOR,
            ]);

            $compra->forceFill([
                'nro_pedido' => $this->generarNumeroPedido($compra->id),
            ])->save();

            foreach ($detallesNormalizados as $detalle) {
                $compra->detalles()->create($detalle);
            }

            foreach ($abonosNormalizados as $abono) {
                $compra->abonos()->create($abono);
            }

            return $this->recalculadoraCompraService->recalcularCompra($compra);
        });

        return response()->json([
            'message' => 'Compra registrada correctamente.',
            'compra' => $this->formatearCompraDetalle($compra),
        ], 201);
    }

    public function detalle(Request $solicitud, Compra $compra): JsonResponse
    {
        if ($respuestaAutorizacion = $this->autorizarGestionCompras($solicitud)) {
            return $respuestaAutorizacion;
        }

        return response()->json([
            'compra' => $this->formatearCompraDetalle(
                $compra->load(['proveedor', 'detalles.producto.marca', 'detalles.producto.categoria'])
            ),
        ]);
    }

    public function actualizar(ActualizarCompraRequest $solicitud, Compra $compra): JsonResponse
    {
        if ($respuestaAutorizacion = $this->autorizarGestionCompras($solicitud)) {
            return $respuestaAutorizacion;
        }

        if (! in_array($compra->estado, [Compra::ESTADO_REGISTRADO, Compra::ESTADO_PENDIENTE_INGRESO_INVENTARIO], true)) {
            return response()->json([
                'message' => 'Solo se pueden editar compras en estado registrado o pendiente de ingreso a inventario.',
            ], 422);
        }

        $datos = $solicitud->validated();
        $detallesNormalizados = $this->recalculadoraCompraService
            ->normalizarDetalles($datos['detalles'], (float) $datos['tipo_cambio_general']);
        $abonosNormalizados = $this->recalculadoraCompraService
            ->normalizarAbonos($datos['abonos'] ?? [], (float) $datos['tipo_cambio_general']);

        foreach ($detallesNormalizados as $detalle) {
            if ($detalle['detalle_texto'] === '') {
                return response()->json([
                    'message' => 'Cada fila del detalle debe tener un producto seleccionado o un detalle libre.',
                ], 422);
            }
        }

        if ($datos['tipo_compra'] === Compra::TIPO_CREDITO && $abonosNormalizados->isNotEmpty()) {
            return response()->json([
                'message' => 'Las compras a credito no admiten abonos directos. Primero deben manejar cuotas.',
            ], 422);
        }

        $totalAbonosUsd = round((float) $abonosNormalizados->sum('abono_usd'), 4);
        $totalProductosUsd = round((float) $detallesNormalizados->sum('subtotal_usd'), 4);

        if ($totalAbonosUsd > $totalProductosUsd + 0.0001) {
            return response()->json([
                'message' => 'La suma de abonos no puede superar el costo total del pedido.',
            ], 422);
        }

        $compra = DB::transaction(function () use ($compra, $datos, $detallesNormalizados, $abonosNormalizados) {
            $compra->forceFill([
                'proveedor_id' => $datos['proveedor_id'],
                'fecha_pedido' => $datos['fecha_pedido'],
                'tipo_compra' => $datos['tipo_compra'],
                'tipo_cambio_general' => $datos['tipo_cambio_general'],
                'tiempo_entrega_dias' => $datos['tiempo_entrega_dias'],
                'observaciones' => $datos['observaciones'] ?? null,
            ])->save();

            $compra->detalles()->delete();
            $compra->abonos()->delete();

            foreach ($detallesNormalizados as $detalle) {
                $compra->detalles()->create($detalle);
            }

            foreach ($abonosNormalizados as $abono) {
                $compra->abonos()->create($abono);
            }

            return $this->recalculadoraCompraService->recalcularCompra($compra);
        });

        return response()->json([
            'message' => 'Compra actualizada correctamente.',
            'compra' => $this->formatearCompraDetalle($compra),
        ]);
    }

    public function registrarProveedor(RegistrarProveedorRequest $solicitud): JsonResponse
    {
        if ($respuestaAutorizacion = $this->autorizarGestionCompras($solicitud)) {
            return $respuestaAutorizacion;
        }

        $datos = $solicitud->validated();
        $nombreProveedor = trim($datos['nombre']);

        $proveedorExistente = Proveedor::query()
            ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombreProveedor)])
            ->first();

        if ($proveedorExistente) {
            return response()->json([
                'message' => 'Se reutilizo un proveedor ya registrado con ese nombre.',
                'proveedor' => $this->formatearProveedor($proveedorExistente),
            ]);
        }

        $proveedor = Proveedor::query()->create([
            'nombre' => $nombreProveedor,
            'contacto' => null,
            'telefono' => null,
            'correo' => null,
            'direccion' => null,
            'observaciones' => null,
            'activo' => true,
        ]);

        return response()->json([
            'message' => 'Proveedor registrado correctamente.',
            'proveedor' => $this->formatearProveedor($proveedor),
        ], 201);
    }

    protected function autorizarGestionCompras(Request $solicitud): ?JsonResponse
    {
        /** @var User $usuario */
        $usuario = $solicitud->user();

        if (! in_array($usuario->rol, [
            User::ROL_GERENTE,
            User::ROL_AUXILIAR_ADMINISTRATIVO,
            User::ROL_SUPERVISOR_SUCURSAL,
        ], true)) {
            return response()->json([
                'message' => 'No tienes permiso para gestionar compras.',
            ], 403);
        }

        return null;
    }

    protected function formatearCompraResumen(Compra $compra): array
    {
        return [
            'id' => $compra->id,
            'proveedor' => $compra->proveedor?->nombre,
            'proveedor_id' => $compra->proveedor_id,
            'fecha_pedido' => optional($compra->fecha_pedido)?->format('d/m/Y'),
            'fecha_pedido_iso' => optional($compra->fecha_pedido)?->toDateString(),
            'tipo_compra' => $compra->tipo_compra,
            'tipo_compra_label' => $this->etiquetaTipoCompra($compra->tipo_compra),
            'nro_pedido' => $compra->nro_pedido,
            'tipo_cambio_general' => (float) $compra->tipo_cambio_general,
            'tiempo_entrega_dias' => (int) $compra->tiempo_entrega_dias,
            'estado' => $compra->estado,
            'estado_label' => $this->etiquetaEstadoCompra($compra->estado),
            'total_productos_usd' => (float) $compra->total_productos_usd,
            'total_productos_bs' => (float) $compra->total_productos_bs,
            'saldo_pendiente_usd' => (float) $compra->saldo_pendiente_usd,
            'saldo_pendiente_bs' => (float) $compra->saldo_pendiente_bs,
            'total_detalles' => $compra->detalles->count(),
        ];
    }

    protected function formatearCompraDetalle(Compra $compra): array
    {
        $compra->loadMissing(['proveedor', 'detalles.producto.marca', 'detalles.producto.categoria', 'abonos.sucursal']);

        return [
            ...$this->formatearCompraResumen($compra),
            'observaciones' => $compra->observaciones,
            'devolucion_pendiente_usd' => (float) $compra->devolucion_pendiente_usd,
            'devolucion_pendiente_bs' => (float) $compra->devolucion_pendiente_bs,
            'detalles' => $compra->detalles
                ->map(fn (CompraDetalle $detalle) => [
                    'id' => $detalle->id,
                    'producto_id' => $detalle->producto_id,
                    'producto_modelo' => $detalle->producto?->modelo,
                    'detalle_texto' => $detalle->detalle_texto,
                    'cantidad_pedida' => (int) $detalle->cantidad_pedida,
                    'cantidad_recibida_acumulada' => (int) $detalle->cantidad_recibida_acumulada,
                    'cantidad_pendiente' => (int) $detalle->cantidad_pendiente,
                    'tipo_cambio_aplicado' => (float) $detalle->tipo_cambio_aplicado,
                    'moneda_referencia' => $detalle->moneda_referencia,
                    'precio_unitario_usd' => (float) $detalle->precio_unitario_usd,
                    'precio_unitario_bs' => (float) $detalle->precio_unitario_bs,
                    'subtotal_usd' => (float) $detalle->subtotal_usd,
                    'subtotal_bs' => (float) $detalle->subtotal_bs,
                    'estado_linea' => $detalle->estado_linea,
                    'observaciones' => $detalle->observaciones,
                ])
                ->values()
                ->all(),
            'abonos' => $compra->abonos
                ->map(fn ($abono) => [
                    'id' => $abono->id,
                    'sucursal_id' => $abono->sucursal_id,
                    'sucursal' => $abono->sucursal?->nombre,
                    'tipo_cambio_abono' => (float) $abono->tipo_cambio_abono,
                    'moneda_referencia' => $abono->moneda_referencia,
                    'abono_usd' => (float) $abono->abono_usd,
                    'abono_bs' => (float) $abono->abono_bs,
                    'fecha_abono' => optional($abono->fecha_abono)?->toDateString(),
                    'observaciones' => $abono->observaciones,
                ])
                ->values()
                ->all(),
        ];
    }

    protected function formatearProveedores(iterable $proveedores): array
    {
        return collect($proveedores)
            ->map(fn (Proveedor $proveedor) => $this->formatearProveedor($proveedor))
            ->values()
            ->all();
    }

    protected function formatearProveedor(Proveedor $proveedor): array
    {
        return [
            'id' => $proveedor->id,
            'value' => $proveedor->id,
            'label' => $proveedor->nombre,
            'nombre' => $proveedor->nombre,
            'contacto' => $proveedor->contacto,
            'telefono' => $proveedor->telefono,
            'correo' => $proveedor->correo,
            'direccion' => $proveedor->direccion,
            'observaciones' => $proveedor->observaciones,
        ];
    }

    protected function formatearSucursales(iterable $sucursales): array
    {
        return collect($sucursales)
            ->map(fn (Sucursal $sucursal) => [
                'id' => $sucursal->id,
                'value' => $sucursal->id,
                'label' => $sucursal->nombre,
                'nombre' => $sucursal->nombre,
            ])
            ->values()
            ->all();
    }

    protected function formatearProductosAutocompletado(iterable $productos): array
    {
        return collect($productos)
            ->map(fn (Producto $producto) => [
                'id' => $producto->id,
                'value' => $producto->id,
                'label' => $producto->modelo,
                'modelo' => $producto->modelo,
                'marca' => $producto->marca?->nombre,
                'categoria' => $producto->categoria?->nombre,
                'precio_unitario_usd' => (float) $producto->precio_unitario_usd,
                'precio_unitario_bs' => (float) $producto->precio_unitario_bs,
                'costo_usd' => (float) $producto->costo_usd,
                'costo_bs' => (float) $producto->costo_bs,
                'tipo_cambio_referencia' => $producto->costo_usd > 0
                    ? round(((float) $producto->costo_bs / (float) $producto->costo_usd), 6)
                    : null,
            ])
            ->values()
            ->all();
    }

    protected function etiquetaTipoCompra(?string $tipoCompra): ?string
    {
        return match ($tipoCompra) {
            Compra::TIPO_CONTADO => 'Contado',
            Compra::TIPO_PEDIDO => 'Pedido',
            Compra::TIPO_CREDITO => 'A credito',
            default => $tipoCompra,
        };
    }

    protected function etiquetaEstadoCompra(?string $estado): ?string
    {
        return match ($estado) {
            Compra::ESTADO_BORRADOR => 'Borrador',
            Compra::ESTADO_REGISTRADO => 'Registrado',
            Compra::ESTADO_EN_TRANSITO => 'En transito',
            Compra::ESTADO_PENDIENTE_RECEPCION => 'Pendiente de recepcion',
            Compra::ESTADO_PENDIENTE_INGRESO_INVENTARIO => 'Pendiente de ingreso a inventario',
            Compra::ESTADO_PARCIAL => 'Parcial',
            Compra::ESTADO_COMPLETADO => 'Completado',
            Compra::ESTADO_INCOMPLETO => 'Incompleto',
            Compra::ESTADO_CERRADO => 'Cerrado',
            default => $estado,
        };
    }

    protected function generarNumeroPedido(int $idCompra): string
    {
        return 'COMP-'.str_pad((string) $idCompra, 6, '0', STR_PAD_LEFT);
    }
}
