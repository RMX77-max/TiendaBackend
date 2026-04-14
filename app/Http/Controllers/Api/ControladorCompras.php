<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ActualizarCompraRequest;
use App\Http\Requests\RegistrarGuiaCompraRequest;
use App\Http\Requests\RegistrarPagoCreditoCompraRequest;
use App\Http\Requests\RegistrarCompraRequest;
use App\Http\Requests\RegistrarRecepcionCompraRequest;
use App\Http\Requests\RegistrarProveedorRequest;
use App\Models\Compra;
use App\Models\CompraAbono;
use App\Models\CompraDetalle;
use App\Models\CompraCuota;
use App\Models\CompraGuia;
use App\Models\CompraRecepcion;
use App\Models\CompraRecepcionDetalle;
use App\Models\ProductoUnidad;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Compras\RecalculadoraCompraService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
            ->with(['proveedor', 'detalles', 'abonos'])
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
        $datos['abonos'] = $this->procesarComprobantesAbonos($datos['abonos'] ?? []);
        $detallesNormalizados = $this->recalculadoraCompraService
            ->normalizarDetalles($datos['detalles'], (float) $datos['tipo_cambio_general']);
        $totalProductosUsd = round((float) $detallesNormalizados->sum('subtotal_usd'), 4);
        $totalProductosBs = round((float) $detallesNormalizados->sum('subtotal_bs'), 4);
        $tipoCambioReferenciaAbonos = $totalProductosUsd > 0
            ? round($totalProductosBs / $totalProductosUsd, 6)
            : (float) $datos['tipo_cambio_general'];
        $abonosNormalizados = $this->recalculadoraCompraService
            ->normalizarAbonos($datos['abonos'] ?? [], $tipoCambioReferenciaAbonos);
        $cuotasNormalizadas = $this->recalculadoraCompraService
            ->normalizarCuotas($datos['cuotas'] ?? [], $totalProductosUsd, $totalProductosBs);

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

        if ($totalAbonosUsd > $totalProductosUsd + 0.0001) {
            return response()->json([
                'message' => 'La suma de abonos no puede superar el costo total del pedido.',
            ], 422);
        }

        if ($datos['tipo_compra'] !== Compra::TIPO_CREDITO && $cuotasNormalizadas->isNotEmpty()) {
            return response()->json([
                'message' => 'Solo las compras a credito pueden registrar cuotas.',
            ], 422);
        }

        $compra = DB::transaction(function () use ($datos, $detallesNormalizados, $abonosNormalizados, $cuotasNormalizadas) {
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

            foreach ($cuotasNormalizadas as $cuota) {
                $compra->cuotas()->create($cuota);
            }

            $this->asegurarRecepcionAutomaticaSiCompraInmediata($compra, $datos['fecha_pedido']);

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
        $datos['abonos'] = $this->procesarComprobantesAbonos($datos['abonos'] ?? []);
        $detallesNormalizados = $this->recalculadoraCompraService
            ->normalizarDetalles($datos['detalles'], (float) $datos['tipo_cambio_general']);
        $totalProductosUsd = round((float) $detallesNormalizados->sum('subtotal_usd'), 4);
        $totalProductosBs = round((float) $detallesNormalizados->sum('subtotal_bs'), 4);
        $tipoCambioReferenciaAbonos = $totalProductosUsd > 0
            ? round($totalProductosBs / $totalProductosUsd, 6)
            : (float) $datos['tipo_cambio_general'];
        $abonosNormalizados = $this->recalculadoraCompraService
            ->normalizarAbonos($datos['abonos'] ?? [], $tipoCambioReferenciaAbonos);
        $cuotasNormalizadas = $this->recalculadoraCompraService
            ->normalizarCuotas($datos['cuotas'] ?? [], $totalProductosUsd, $totalProductosBs);

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

        if ($totalAbonosUsd > $totalProductosUsd + 0.0001) {
            return response()->json([
                'message' => 'La suma de abonos no puede superar el costo total del pedido.',
            ], 422);
        }

        if ($datos['tipo_compra'] !== Compra::TIPO_CREDITO && $cuotasNormalizadas->isNotEmpty()) {
            return response()->json([
                'message' => 'Solo las compras a credito pueden registrar cuotas.',
            ], 422);
        }

        $compra = DB::transaction(function () use ($compra, $datos, $detallesNormalizados, $abonosNormalizados, $cuotasNormalizadas) {
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
            $compra->cuotas()->delete();

            foreach ($detallesNormalizados as $detalle) {
                $compra->detalles()->create($detalle);
            }

            foreach ($abonosNormalizados as $abono) {
                $compra->abonos()->create($abono);
            }

            foreach ($cuotasNormalizadas as $cuota) {
                $compra->cuotas()->create($cuota);
            }

            $this->asegurarRecepcionAutomaticaSiCompraInmediata($compra, $datos['fecha_pedido']);

            return $this->recalculadoraCompraService->recalcularCompra($compra);
        });

        return response()->json([
            'message' => 'Compra actualizada correctamente.',
            'compra' => $this->formatearCompraDetalle($compra),
        ]);
    }

    public function registrarPagoCredito(RegistrarPagoCreditoCompraRequest $solicitud, Compra $compra): JsonResponse
    {
        if ($respuestaAutorizacion = $this->autorizarGestionCompras($solicitud)) {
            return $respuestaAutorizacion;
        }

        if ($compra->tipo_compra !== Compra::TIPO_CREDITO) {
            return response()->json([
                'message' => 'Solo las compras a credito admiten pagos posteriores desde esta opcion.',
            ], 422);
        }

        $datos = $solicitud->validated();
        $tipoCambioReferenciaCompra = $this->obtenerTipoCambioReferenciaCompra($compra);
        $abonoNormalizado = $this->recalculadoraCompraService
            ->normalizarAbonos([$datos], $tipoCambioReferenciaCompra)
            ->first();

        $totalAbonosActualUsd = round((float) $compra->abonos()->sum('abono_usd'), 4);
        $totalProductosUsd = (float) $compra->total_productos_usd;
        $totalConNuevoPagoUsd = round($totalAbonosActualUsd + (float) $abonoNormalizado['abono_usd'], 4);

        if ($totalConNuevoPagoUsd > $totalProductosUsd + 0.0001) {
            return response()->json([
                'message' => 'El pago supera el saldo pendiente de la compra.',
            ], 422);
        }

        $compra = DB::transaction(function () use ($compra, $abonoNormalizado) {
            $compra->abonos()->create($abonoNormalizado);

            return $this->recalculadoraCompraService->recalcularCompra($compra);
        });

        return response()->json([
            'message' => 'Pago registrado correctamente.',
            'compra' => $this->formatearCompraDetalle($compra),
        ], 201);
    }

    public function registrarGuia(RegistrarGuiaCompraRequest $solicitud, Compra $compra): JsonResponse
    {
        if ($respuestaAutorizacion = $this->autorizarGestionCompras($solicitud)) {
            return $respuestaAutorizacion;
        }

        if ((int) $compra->tiempo_entrega_dias === 0) {
            return response()->json([
                'message' => 'Las compras inmediatas no necesitan guias.',
            ], 422);
        }

        $datos = $solicitud->validated();

        $compra = DB::transaction(function () use ($solicitud, $compra, $datos) {
            $compra->guias()->create([
                'fecha_registro' => $datos['fecha_registro'],
                'monto_bs' => $datos['monto_bs'],
                'estado' => $datos['estado'],
                'pagado' => (bool) ($datos['pagado'] ?? false),
                'observaciones' => $datos['observaciones'] ?? null,
                'foto_path' => $this->guardarFotoGuia($solicitud),
            ]);

            return $this->recalculadoraCompraService->recalcularCompra($compra);
        });

        return response()->json([
            'message' => 'Guia registrada correctamente.',
            'compra' => $this->formatearCompraDetalle($compra),
        ], 201);
    }

    public function actualizarGuia(RegistrarGuiaCompraRequest $solicitud, CompraGuia $guia): JsonResponse
    {
        if ($respuestaAutorizacion = $this->autorizarGestionCompras($solicitud)) {
            return $respuestaAutorizacion;
        }

        $compra = $guia->compra()->firstOrFail();

        if ((int) $compra->tiempo_entrega_dias === 0) {
            return response()->json([
                'message' => 'Las compras inmediatas no necesitan guias.',
            ], 422);
        }

        if (CompraRecepcion::query()->where('compra_guia_id', $guia->id)->exists()) {
            return response()->json([
                'message' => 'Esta guia ya fue usada en una recepcion y no se puede editar.',
            ], 422);
        }

        $datos = $solicitud->validated();

        $compra = DB::transaction(function () use ($solicitud, $guia, $compra, $datos) {
            $atributos = [
                'fecha_registro' => $datos['fecha_registro'],
                'monto_bs' => $datos['monto_bs'],
                'estado' => $datos['estado'],
                'pagado' => (bool) ($datos['pagado'] ?? false),
                'observaciones' => $datos['observaciones'] ?? null,
            ];

            if ($nuevaFotoPath = $this->guardarFotoGuia($solicitud, $guia->foto_path)) {
                $atributos['foto_path'] = $nuevaFotoPath;
            }

            $guia->forceFill($atributos)->save();

            return $this->recalculadoraCompraService->recalcularCompra($compra);
        });

        return response()->json([
            'message' => 'Guia actualizada correctamente.',
            'compra' => $this->formatearCompraDetalle($compra),
        ]);
    }

    public function registrarRecepcion(RegistrarRecepcionCompraRequest $solicitud, Compra $compra): JsonResponse
    {
        if ($respuestaAutorizacion = $this->autorizarGestionCompras($solicitud)) {
            return $respuestaAutorizacion;
        }

        $datos = $solicitud->validated();
        $guia = null;

        if (! empty($datos['compra_guia_id'])) {
            $guia = CompraGuia::query()->find($datos['compra_guia_id']);

            if (! $guia || (int) $guia->compra_id !== (int) $compra->id) {
                return response()->json([
                    'message' => 'La guia seleccionada no pertenece a esta compra.',
                ], 422);
            }

            if ($guia->estado !== CompraGuia::ESTADO_RECOGIDO) {
                return response()->json([
                    'message' => 'Solo se puede recepcionar con una guia en estado recogido.',
                ], 422);
            }

            if (CompraRecepcion::query()->where('compra_guia_id', $guia->id)->exists()) {
                return response()->json([
                    'message' => 'La guia seleccionada ya fue usada en una recepcion anterior.',
                ], 422);
            }
        } elseif ((int) $compra->tiempo_entrega_dias > 0) {
            return response()->json([
                'message' => 'Debes seleccionar una guia recogida para registrar esta recepcion.',
            ], 422);
        }

        $detallesCompra = $compra->detalles()->get()->keyBy('id');
        $lineasRecepcion = collect($datos['detalles']);

        foreach ($lineasRecepcion as $linea) {
            /** @var CompraDetalle|null $detalleCompra */
            $detalleCompra = $detallesCompra->get((int) $linea['compra_detalle_id']);

            if (! $detalleCompra) {
                return response()->json([
                    'message' => 'Uno de los productos recepcionados no pertenece a esta compra.',
                ], 422);
            }

            $cantidadRecibida = (int) $linea['cantidad_recibida'];

            if ($cantidadRecibida > (int) $detalleCompra->cantidad_pendiente) {
                return response()->json([
                    'message' => 'No puedes recepcionar mas cantidad de la pendiente en una de las lineas.',
                ], 422);
            }
        }

        $compra = DB::transaction(function () use ($compra, $datos, $guia, $lineasRecepcion, $detallesCompra) {
            $recepcion = $compra->recepciones()->create([
                'compra_guia_id' => $guia?->id,
                'fecha_recepcion' => $datos['fecha_recepcion'],
                'estado_recepcion' => CompraRecepcion::ESTADO_PARCIAL,
                'observaciones' => $datos['observaciones'] ?? null,
                'ingresado_inventario' => false,
                'fecha_ingreso_inventario' => null,
            ]);

            foreach ($lineasRecepcion as $linea) {
                /** @var CompraDetalle $detalleCompra */
                $detalleCompra = $detallesCompra->get((int) $linea['compra_detalle_id']);
                $cantidadRecibida = (int) $linea['cantidad_recibida'];
                $nuevoAcumulado = (int) $detalleCompra->cantidad_recibida_acumulada + $cantidadRecibida;
                $cantidadPendiente = max(0, (int) $detalleCompra->cantidad_pedida - $nuevoAcumulado);

                $recepcion->detalles()->create([
                    'compra_detalle_id' => $detalleCompra->id,
                    'cantidad_recibida' => $cantidadRecibida,
                    'observaciones' => $linea['observaciones'] ?? null,
                ]);

                $detalleCompra->forceFill([
                    'cantidad_recibida_acumulada' => $nuevoAcumulado,
                    'cantidad_pendiente' => $cantidadPendiente,
                    'estado_linea' => $this->recalculadoraCompraService->determinarEstadoLinea(
                        (int) $detalleCompra->cantidad_pedida,
                        $nuevoAcumulado
                    ),
                ])->save();
            }

            $compraActualizada = $this->recalculadoraCompraService->recalcularCompra($compra);
            $estadoRecepcion = $compraActualizada->detalles->sum('cantidad_pendiente') === 0
                ? CompraRecepcion::ESTADO_COMPLETA
                : CompraRecepcion::ESTADO_PARCIAL;

            $recepcion->forceFill([
                'estado_recepcion' => $estadoRecepcion,
            ])->save();

            return $compraActualizada;
        });

        return response()->json([
            'message' => 'Recepcion registrada correctamente.',
            'compra' => $this->formatearCompraDetalle($compra),
        ], 201);
    }

    public function ingresarRecepcionInventario(Request $solicitud, CompraRecepcion $recepcion): JsonResponse
    {
        if ($respuestaAutorizacion = $this->autorizarGestionCompras($solicitud)) {
            return $respuestaAutorizacion;
        }

        $recepcion->loadMissing(['compra.detalles', 'detalles.compraDetalle']);

        if ($recepcion->ingresado_inventario) {
            return response()->json([
                'message' => 'La recepcion seleccionada ya fue ingresada al inventario.',
            ], 422);
        }

        $datos = $solicitud->validate([
            'detalles' => ['required', 'array', 'min:1'],
            'detalles.*.recepcion_detalle_id' => ['required', 'integer', Rule::exists('compra_recepcion_detalles', 'id')],
            'detalles.*.producto_id' => ['nullable', 'integer', Rule::exists('productos', 'id')],
            'detalles.*.crear_producto' => ['required', 'boolean'],
            'detalles.*.modelo' => ['nullable', 'string', 'max:120'],
            'detalles.*.marca_id' => ['nullable', 'integer', Rule::exists('marcas', 'id')],
            'detalles.*.categoria_id' => ['nullable', 'integer', Rule::exists('categorias', 'id')],
            'detalles.*.tipo_cambio_venta' => ['nullable', 'numeric', 'gt:0'],
            'detalles.*.precio_unitario_usd' => ['nullable', 'numeric', 'min:0'],
            'detalles.*.precio_unitario_bs' => ['nullable', 'numeric', 'min:0'],
            'detalles.*.precio_mayorista_usd' => ['nullable', 'numeric', 'min:0'],
            'detalles.*.precio_mayorista_bs' => ['nullable', 'numeric', 'min:0'],
            'detalles.*.registrar_series' => ['required', 'boolean'],
            'detalles.*.distribucion_sucursales' => ['required', 'array', 'min:1'],
            'detalles.*.distribucion_sucursales.*.sucursal_id' => ['required', 'integer', Rule::exists('sucursales', 'id')],
            'detalles.*.distribucion_sucursales.*.cantidad' => ['required', 'integer', 'min:0'],
            'detalles.*.series' => ['nullable', 'array'],
            'detalles.*.series.*.sucursal_id' => ['required_with:detalles.*.series', 'integer', Rule::exists('sucursales', 'id')],
            'detalles.*.series.*.numero_serie' => ['nullable', 'string', 'max:150', 'distinct', 'unique:producto_unidades,numero_serie'],
            'detalles.*.series.*.observaciones' => ['nullable', 'string', 'max:255'],
        ]);

        $detallesRecepcion = $recepcion->detalles->keyBy('id');

        foreach ($datos['detalles'] as $linea) {
            /** @var CompraRecepcionDetalle|null $detalleRecepcion */
            $detalleRecepcion = $detallesRecepcion->get((int) $linea['recepcion_detalle_id']);

            if (! $detalleRecepcion) {
                return response()->json([
                    'message' => 'Una de las lineas enviadas no pertenece a esta recepcion.',
                ], 422);
            }

            $cantidadRecibida = (int) $detalleRecepcion->cantidad_recibida;
            $cantidadDistribuida = collect($linea['distribucion_sucursales'])->sum(fn ($item) => (int) $item['cantidad']);

            if ($cantidadDistribuida !== $cantidadRecibida) {
                return response()->json([
                    'message' => 'La distribucion por sucursales debe coincidir con la cantidad recibida de cada linea.',
                ], 422);
            }

            if ($linea['crear_producto']) {
                if (! filled($linea['modelo'] ?? null) || empty($linea['marca_id']) || empty($linea['categoria_id'])) {
                    return response()->json([
                        'message' => 'Para crear un producto nuevo debes indicar modelo, marca y categoria.',
                    ], 422);
                }

                if (! isset($linea['precio_unitario_usd'], $linea['precio_unitario_bs'], $linea['precio_mayorista_usd'], $linea['precio_mayorista_bs'])) {
                    return response()->json([
                        'message' => 'Debes definir los precios de venta para cada producto nuevo que ingresara al inventario.',
                    ], 422);
                }
            } elseif (empty($linea['producto_id']) && empty($detalleRecepcion->compraDetalle?->producto_id)) {
                return response()->json([
                    'message' => 'Debes seleccionar un producto existente o crear uno nuevo antes de ingresar la recepcion al inventario.',
                ], 422);
            }

            if ($linea['registrar_series']) {
                $series = collect($linea['series'] ?? []);

                if ($series->count() !== $cantidadRecibida) {
                    return response()->json([
                        'message' => 'Debes registrar una serie por cada unidad cuando activas el registro de series.',
                    ], 422);
                }

                $conteoSeriesPorSucursal = $series
                    ->groupBy('sucursal_id')
                    ->map(fn ($items) => $items->count());

                foreach ($linea['distribucion_sucursales'] as $distribucion) {
                    $cantidadSeriesSucursal = (int) ($conteoSeriesPorSucursal[$distribucion['sucursal_id']] ?? 0);

                    if ($cantidadSeriesSucursal !== (int) $distribucion['cantidad']) {
                        return response()->json([
                            'message' => 'La cantidad de series por sucursal debe coincidir con la distribucion de esa linea.',
                        ], 422);
                    }
                }
            }
        }

        $compra = DB::transaction(function () use ($recepcion, $datos, $detallesRecepcion) {
            foreach ($datos['detalles'] as $linea) {
                /** @var CompraRecepcionDetalle $detalleRecepcion */
                $detalleRecepcion = $detallesRecepcion->get((int) $linea['recepcion_detalle_id']);
                /** @var CompraDetalle $detalleCompra */
                $detalleCompra = $detalleRecepcion->compraDetalle;

                $producto = null;

                if ($linea['crear_producto']) {
                    $producto = Producto::query()->create([
                        'modelo' => trim((string) $linea['modelo']),
                        'marca_id' => $linea['marca_id'],
                        'categoria_id' => $linea['categoria_id'],
                        'precio_unitario_usd' => $linea['precio_unitario_usd'],
                        'precio_unitario_bs' => $linea['precio_unitario_bs'],
                        'precio_mayorista_usd' => $linea['precio_mayorista_usd'],
                        'precio_mayorista_bs' => $linea['precio_mayorista_bs'],
                        'costo_usd' => $detalleCompra->precio_unitario_usd,
                        'costo_bs' => $detalleCompra->precio_unitario_bs,
                        'activo' => true,
                    ]);
                } else {
                    $productoId = $linea['producto_id'] ?? $detalleCompra->producto_id;
                    $producto = Producto::query()->findOrFail($productoId);
                    $producto->forceFill([
                        'costo_usd' => $this->calcularCostoPromedioPonderado(
                            $producto,
                            (int) $detalleRecepcion->cantidad_recibida,
                            (float) $detalleCompra->precio_unitario_usd,
                            'usd'
                        ),
                        'costo_bs' => $this->calcularCostoPromedioPonderado(
                            $producto,
                            (int) $detalleRecepcion->cantidad_recibida,
                            (float) $detalleCompra->precio_unitario_bs,
                            'bs'
                        ),
                    ])->save();
                }

                $detalleCompra->forceFill([
                    'producto_id' => $producto->id,
                    'detalle_texto' => $producto->modelo,
                ])->save();

                if ($linea['registrar_series']) {
                    foreach ($linea['series'] as $serie) {
                        $producto->unidades()->create([
                            'sucursal_id' => $serie['sucursal_id'],
                            'numero_serie' => filled($serie['numero_serie'] ?? null)
                                ? trim((string) $serie['numero_serie'])
                                : null,
                            'estado' => ProductoUnidad::ESTADO_DISPONIBLE,
                            'observaciones' => $serie['observaciones'] ?? null,
                            'fecha_ingreso' => $recepcion->fecha_recepcion,
                            'activo' => true,
                        ]);
                    }
                } else {
                    foreach ($linea['distribucion_sucursales'] as $distribucion) {
                        for ($indice = 0; $indice < (int) $distribucion['cantidad']; $indice++) {
                            $producto->unidades()->create([
                                'sucursal_id' => $distribucion['sucursal_id'],
                                'numero_serie' => null,
                                'estado' => ProductoUnidad::ESTADO_DISPONIBLE,
                                'observaciones' => null,
                                'fecha_ingreso' => $recepcion->fecha_recepcion,
                                'activo' => true,
                            ]);
                        }
                    }
                }
            }

            $recepcion->forceFill([
                'ingresado_inventario' => true,
                'fecha_ingreso_inventario' => now(),
            ])->save();

            return $this->recalculadoraCompraService->recalcularCompra($recepcion->compra);
        });

        return response()->json([
            'message' => 'Recepcion ingresada al inventario correctamente.',
            'compra' => $this->formatearCompraDetalle($compra),
        ]);
    }

    public function cerrarIncompleto(Request $solicitud, Compra $compra): JsonResponse
    {
        if ($respuestaAutorizacion = $this->autorizarGestionCompras($solicitud)) {
            return $respuestaAutorizacion;
        }

        $datos = $solicitud->validate([
            'confirmar_cierre' => ['required', 'accepted'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ]);

        $compra->loadMissing('detalles');

        if ((int) $compra->detalles->sum('cantidad_pendiente') <= 0) {
            return response()->json([
                'message' => 'Esta compra ya no tiene cantidades pendientes por cerrar como incompletas.',
            ], 422);
        }

        if ($compra->estado === Compra::ESTADO_CERRADO) {
            return response()->json([
                'message' => 'La compra ya se encuentra cerrada y no admite este cambio.',
            ], 422);
        }

        $compra = DB::transaction(function () use ($compra, $datos) {
            foreach ($compra->detalles as $detalle) {
                if ((int) $detalle->cantidad_pendiente > 0) {
                    $detalle->forceFill([
                        'estado_linea' => CompraDetalle::ESTADO_INCOMPLETO,
                    ])->save();
                }
            }

            $observacionesActualizadas = trim(implode(' | ', array_filter([
                $compra->observaciones,
                $datos['observaciones'] ?? null,
            ])));

            $compra->forceFill([
                'estado' => Compra::ESTADO_INCOMPLETO,
                'cerrado_forzado' => true,
                'fecha_cierre' => now(),
                'observaciones' => $observacionesActualizadas !== '' ? $observacionesActualizadas : null,
            ])->save();

            return $this->recalculadoraCompraService->recalcularCompra($compra);
        });

        return response()->json([
            'message' => 'Compra cerrada como incompleta correctamente.',
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
        $estadoPago = $this->determinarEstadoPagoCompra($compra);
        $resumenDiferenciaCambiaria = $this->calcularResumenDiferenciaCambiaria($compra);

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
            'total_pagado_usd' => (float) $compra->total_abonos_usd,
            'total_pagado_bs' => (float) $compra->total_abonos_bs,
            'referencia_pagado_bs' => $resumenDiferenciaCambiaria['referencia_pagado_bs'],
            'diferencia_cambiaria_total_bs' => $resumenDiferenciaCambiaria['diferencia_cambiaria_total_bs'],
            'diferencia_cambiaria_total_tipo' => $resumenDiferenciaCambiaria['diferencia_cambiaria_total_tipo'],
            'estado_pago' => $estadoPago,
            'estado_pago_label' => $this->etiquetaEstadoPagoCompra($estadoPago),
            'total_detalles' => $compra->detalles->count(),
        ];
    }

    protected function formatearCompraDetalle(Compra $compra): array
    {
        $compra->loadMissing(['proveedor', 'detalles.producto.marca', 'detalles.producto.categoria', 'abonos.sucursal', 'cuotas', 'guias', 'recepciones.detalles.compraDetalle']);

        return [
            ...$this->formatearCompraResumen($compra),
            'observaciones' => $compra->observaciones,
            'devolucion_pendiente_usd' => (float) $compra->devolucion_pendiente_usd,
            'devolucion_pendiente_bs' => (float) $compra->devolucion_pendiente_bs,
            'total_guias_bs' => (float) $compra->total_guias_bs,
            'detalles' => $compra->detalles
                ->map(fn (CompraDetalle $detalle) => [
                    'id' => $detalle->id,
                    'producto_id' => $detalle->producto_id,
                    'producto_modelo' => $detalle->producto?->modelo,
                    'marca_id' => $detalle->producto?->marca_id,
                    'marca' => $detalle->producto?->marca?->nombre,
                    'categoria_id' => $detalle->producto?->categoria_id,
                    'categoria' => $detalle->producto?->categoria?->nombre,
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
                ->map(fn (CompraAbono $abono) => $this->formatearAbonoCompra($abono))
                ->values()
                ->all(),
            'cuotas' => $compra->cuotas
                ->map(fn (CompraCuota $cuota) => [
                    'id' => $cuota->id,
                    'nro_cuota' => (int) $cuota->nro_cuota,
                    'fecha_vencimiento' => optional($cuota->fecha_vencimiento)?->toDateString(),
                    'tipo_cambio_referencia' => (float) $cuota->tipo_cambio_referencia,
                    'monto_usd' => (float) $cuota->monto_usd,
                    'monto_bs' => (float) $cuota->monto_bs,
                    'saldo_pendiente_usd' => (float) $cuota->saldo_pendiente_usd,
                    'saldo_pendiente_bs' => (float) $cuota->saldo_pendiente_bs,
                    'estado' => $cuota->estado,
                    'observaciones' => $cuota->observaciones,
                ])
                ->values()
                ->all(),
            'guias' => $compra->guias
                ->map(fn (CompraGuia $guia) => [
                    'id' => $guia->id,
                    'fecha_registro' => optional($guia->fecha_registro)?->toDateString(),
                    'monto_bs' => (float) $guia->monto_bs,
                    'estado' => $guia->estado,
                    'estado_label' => $this->etiquetaEstadoGuia($guia->estado),
                    'pagado' => (bool) $guia->pagado,
                    'foto_path' => $guia->foto_path,
                    'foto_url' => $guia->foto_path ? Storage::disk('public')->url($guia->foto_path) : null,
                    'observaciones' => $guia->observaciones,
                ])
                ->values()
                ->all(),
            'recepciones' => $compra->recepciones
                ->map(fn (CompraRecepcion $recepcion) => [
                    'id' => $recepcion->id,
                    'compra_guia_id' => $recepcion->compra_guia_id,
                    'fecha_recepcion' => optional($recepcion->fecha_recepcion)?->toDateString(),
                    'estado_recepcion' => $recepcion->estado_recepcion,
                    'estado_recepcion_label' => $this->etiquetaEstadoRecepcion($recepcion->estado_recepcion),
                    'ingresado_inventario' => (bool) $recepcion->ingresado_inventario,
                    'fecha_ingreso_inventario' => optional($recepcion->fecha_ingreso_inventario)?->toDateTimeString(),
                    'observaciones' => $recepcion->observaciones,
                    'detalles' => $recepcion->detalles
                        ->map(fn (CompraRecepcionDetalle $detalleRecepcion) => [
                            'id' => $detalleRecepcion->id,
                            'compra_detalle_id' => $detalleRecepcion->compra_detalle_id,
                            'detalle_texto' => $detalleRecepcion->compraDetalle?->detalle_texto,
                            'cantidad_recibida' => (int) $detalleRecepcion->cantidad_recibida,
                            'observaciones' => $detalleRecepcion->observaciones,
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    protected function formatearAbonoCompra(CompraAbono $abono): array
    {
        $tipoCambioReferencia = $this->obtenerTipoCambioReferenciaCompra($abono->compra);
        $referenciaBs = round((float) $abono->abono_usd * $tipoCambioReferencia, 4);
        $diferenciaCambiariaBs = round((float) $abono->abono_bs - $referenciaBs, 4);

        return [
            'id' => $abono->id,
            'sucursal_id' => $abono->sucursal_id,
            'sucursal' => $abono->sucursal?->nombre,
            'tipo_cambio_abono' => (float) $abono->tipo_cambio_abono,
            'tipo_cambio_referencia' => $tipoCambioReferencia,
            'moneda_referencia' => $abono->moneda_referencia,
            'abono_usd' => (float) $abono->abono_usd,
            'abono_bs' => (float) $abono->abono_bs,
            'referencia_bs' => $referenciaBs,
            'diferencia_cambiaria_bs' => $diferenciaCambiariaBs,
            'diferencia_cambiaria_tipo' => $this->determinarTipoDiferenciaCambiaria($diferenciaCambiariaBs),
            'fecha_abono' => optional($abono->fecha_abono)?->toDateString(),
            'comprobante_path' => $abono->comprobante_path,
            'comprobante_url' => $abono->comprobante_path ? Storage::disk('public')->url($abono->comprobante_path) : null,
            'observaciones' => $abono->observaciones,
        ];
    }

    protected function calcularResumenDiferenciaCambiaria(Compra $compra): array
    {
        $tipoCambioReferencia = $this->obtenerTipoCambioReferenciaCompra($compra);
        $referenciaPagadoBs = round((float) $compra->abonos->sum(function (CompraAbono $abono) use ($tipoCambioReferencia) {
            return (float) $abono->abono_usd * $tipoCambioReferencia;
        }), 4);

        $diferenciaCambiariaTotalBs = round((float) $compra->abonos->sum(function (CompraAbono $abono) use ($tipoCambioReferencia) {
            $referenciaBs = (float) $abono->abono_usd * $tipoCambioReferencia;

            return (float) $abono->abono_bs - $referenciaBs;
        }), 4);

        return [
            'referencia_pagado_bs' => $referenciaPagadoBs,
            'diferencia_cambiaria_total_bs' => $diferenciaCambiariaTotalBs,
            'diferencia_cambiaria_total_tipo' => $this->determinarTipoDiferenciaCambiaria($diferenciaCambiariaTotalBs),
        ];
    }

    protected function determinarTipoDiferenciaCambiaria(float $diferenciaCambiariaBs): string
    {
        if ($diferenciaCambiariaBs > 0.0001) {
            return 'perdida';
        }

        if ($diferenciaCambiariaBs < -0.0001) {
            return 'ahorro';
        }

        return 'sin_diferencia';
    }

    protected function obtenerTipoCambioReferenciaCompra(?Compra $compra): float
    {
        if (! $compra) {
            return 0;
        }

        $totalProductosUsd = (float) $compra->total_productos_usd;
        $totalProductosBs = (float) $compra->total_productos_bs;

        if ($totalProductosUsd > 0 && $totalProductosBs > 0) {
            return round($totalProductosBs / $totalProductosUsd, 6);
        }

        return (float) $compra->tipo_cambio_general;
    }

    protected function determinarEstadoPagoCompra(Compra $compra): string
    {
        $totalPagadoUsd = (float) $compra->total_abonos_usd;
        $saldoPendienteUsd = (float) $compra->saldo_pendiente_usd;

        if ($saldoPendienteUsd <= 0.0001) {
            return 'pagado';
        }

        if ($totalPagadoUsd > 0.0001) {
            return 'pago_parcial';
        }

        return 'pendiente_por_pagar';
    }

    protected function etiquetaEstadoPagoCompra(string $estadoPago): string
    {
        return match ($estadoPago) {
            'pagado' => 'Pagado',
            'pago_parcial' => 'Pago parcial',
            'pendiente_por_pagar' => 'Pendiente por pagar',
            default => $estadoPago,
        };
    }

    protected function guardarFotoGuia(Request $solicitud, ?string $fotoActual = null): ?string
    {
        if (! $solicitud->hasFile('foto_guia')) {
            return null;
        }

        if ($fotoActual) {
            Storage::disk('public')->delete($fotoActual);
        }

        return $solicitud->file('foto_guia')->store('compras/guias', 'public');
    }

    protected function etiquetaEstadoGuia(?string $estado): ?string
    {
        return match ($estado) {
            CompraGuia::ESTADO_EN_TRANSITO => 'En transito',
            CompraGuia::ESTADO_RECOGIDO => 'Recogido',
            default => $estado,
        };
    }

    protected function etiquetaEstadoRecepcion(?string $estado): ?string
    {
        return match ($estado) {
            CompraRecepcion::ESTADO_PARCIAL => 'Parcial',
            CompraRecepcion::ESTADO_COMPLETA => 'Completa',
            default => $estado,
        };
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

    protected function procesarComprobantesAbonos(array $abonos): array
    {
        return collect($abonos)
            ->map(function (array $abono) {
                if (! empty($abono['comprobante_foto']) && $abono['comprobante_foto']->isValid()) {
                    $abono['comprobante_path'] = $abono['comprobante_foto']->store('compras/abonos', 'public');
                }

                unset($abono['comprobante_foto']);

                return $abono;
            })
            ->values()
            ->all();
    }

    protected function calcularCostoPromedioPonderado(Producto $producto, int $cantidadEntrante, float $costoCompra, string $moneda): float
    {
        $cantidadActual = ProductoUnidad::query()
            ->where('producto_id', $producto->id)
            ->where('activo', true)
            ->where('estado', ProductoUnidad::ESTADO_DISPONIBLE)
            ->count();

        if ($cantidadActual <= 0 || $cantidadEntrante <= 0) {
            return round($costoCompra, 2);
        }

        $costoActual = $moneda === 'bs'
            ? (float) $producto->costo_bs
            : (float) $producto->costo_usd;

        $nuevoCostoPromedio = (
            ($cantidadActual * $costoActual) +
            ($cantidadEntrante * $costoCompra)
        ) / ($cantidadActual + $cantidadEntrante);

        return round($nuevoCostoPromedio, 2);
    }

    protected function asegurarRecepcionAutomaticaSiCompraInmediata(Compra $compra, string $fechaPedido): void
    {
        if ((int) $compra->tiempo_entrega_dias !== 0) {
            return;
        }

        $compra->loadMissing(['detalles', 'recepciones']);

        if ($compra->recepciones->isNotEmpty()) {
            return;
        }

        $recepcion = $compra->recepciones()->create([
            'compra_guia_id' => null,
            'fecha_recepcion' => $fechaPedido,
            'estado_recepcion' => CompraRecepcion::ESTADO_COMPLETA,
            'observaciones' => 'Recepcion automatica por compra inmediata.',
            'ingresado_inventario' => false,
            'fecha_ingreso_inventario' => null,
        ]);

        foreach ($compra->detalles as $detalle) {
            $cantidadPedida = (int) $detalle->cantidad_pedida;

            $recepcion->detalles()->create([
                'compra_detalle_id' => $detalle->id,
                'cantidad_recibida' => $cantidadPedida,
                'observaciones' => null,
            ]);

            $detalle->forceFill([
                'cantidad_recibida_acumulada' => $cantidadPedida,
                'cantidad_pendiente' => 0,
                'estado_linea' => CompraDetalle::ESTADO_COMPLETO,
            ])->save();
        }
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





