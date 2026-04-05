<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProductoUnidad;
use App\Models\SolicitudTransferencia;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ControladorProductos extends Controller
{
    public function obtenerFormulario(): JsonResponse
    {
        return response()->json([
            'marcas' => $this->formatearMarcas(
                Marca::query()->where('activa', true)->orderBy('nombre')->get()
            ),
            'categorias' => $this->formatearCategorias(
                Categoria::query()->where('activa', true)->orderBy('nombre')->get()
            ),
            'sucursales' => $this->formatearSucursales(
                Sucursal::query()->where('activa', true)->orderBy('nombre')->get()
            ),
        ]);
    }

    public function listar(): JsonResponse
    {
        $productos = Producto::query()
            ->where('activo', true)
            ->with(['marca', 'categoria', 'unidades.sucursal'])
            ->latest()
            ->get()
            ->map(fn (Producto $producto) => $this->formatearProducto($producto))
            ->values();

        return response()->json([
            'productos' => $productos,
        ]);
    }

    public function detalle(Producto $producto): JsonResponse
    {
        if (! $producto->activo) {
            return response()->json([
                'message' => 'El producto seleccionado no se encuentra disponible.',
            ], 404);
        }

        $producto->load(['marca', 'categoria', 'unidades.sucursal']);

        return response()->json([
            'producto' => $this->formatearProducto($producto),
            'operaciones' => $this->obtenerOperacionesDetalle($producto),
        ]);
    }

    public function registrar(Request $solicitud): JsonResponse
    {
        $datos = $solicitud->validate([
            'modelo' => ['required', 'string', 'max:120'],
            'marca_id' => ['required', 'integer', Rule::exists('marcas', 'id')],
            'categoria_id' => ['required', 'integer', Rule::exists('categorias', 'id')],
            'tipo_cambio' => ['required', 'numeric', 'gt:0'],
            'precio_unitario_usd' => ['required', 'numeric', 'min:0'],
            'precio_unitario_bs' => ['required', 'numeric', 'min:0'],
            'precio_mayorista_usd' => ['nullable', 'numeric', 'min:0'],
            'precio_mayorista_bs' => ['nullable', 'numeric', 'min:0'],
            'costo_usd' => ['required', 'numeric', 'min:0'],
            'costo_bs' => ['required', 'numeric', 'min:0'],
            'cantidad_total' => ['required', 'integer', 'min:1'],
            'distribucion_sucursales' => ['required', 'array', 'min:1'],
            'distribucion_sucursales.*.sucursal_id' => ['required', 'integer', Rule::exists('sucursales', 'id')],
            'distribucion_sucursales.*.cantidad' => ['required', 'integer', 'min:0'],
            'registrar_series' => ['required', 'boolean'],
            'series' => ['nullable', 'array'],
            'series.*.sucursal_id' => ['required_with:series', 'integer', Rule::exists('sucursales', 'id')],
            'series.*.numero_serie' => ['nullable', 'string', 'max:150', 'distinct', 'unique:producto_unidades,numero_serie'],
            'series.*.observaciones' => ['nullable', 'string', 'max:255'],
        ]);

        $cantidadDistribuida = collect($datos['distribucion_sucursales'])->sum('cantidad');

        if ($cantidadDistribuida !== (int) $datos['cantidad_total']) {
            return response()->json([
                'message' => 'La suma de cantidades por sucursal debe ser igual a la cantidad total del producto.',
            ], 422);
        }

        if ($datos['registrar_series']) {
            $series = collect($datos['series'] ?? []);

            if ($series->count() !== (int) $datos['cantidad_total']) {
                return response()->json([
                    'message' => 'Debes registrar una fila de serie por cada unidad cuando activas el registro de series.',
                ], 422);
            }

            $conteoSeriesPorSucursal = $series
                ->groupBy('sucursal_id')
                ->map(fn ($items) => $items->count());

            foreach ($datos['distribucion_sucursales'] as $distribucion) {
                $cantidadSeriesSucursal = (int) ($conteoSeriesPorSucursal[$distribucion['sucursal_id']] ?? 0);

                if ($cantidadSeriesSucursal !== (int) $distribucion['cantidad']) {
                    return response()->json([
                        'message' => 'La cantidad de series cargadas por sucursal debe coincidir con la distribucion indicada.',
                    ], 422);
                }
            }
        }

        $producto = DB::transaction(function () use ($datos) {
            $producto = Producto::query()->create([
                'modelo' => trim($datos['modelo']),
                'marca_id' => $datos['marca_id'],
                'categoria_id' => $datos['categoria_id'],
                'precio_unitario_usd' => $datos['precio_unitario_usd'],
                'precio_unitario_bs' => $datos['precio_unitario_bs'],
                'precio_mayorista_usd' => $datos['precio_mayorista_usd'] ?? $datos['precio_unitario_usd'],
                'precio_mayorista_bs' => $datos['precio_mayorista_bs'] ?? $datos['precio_unitario_bs'],
                'costo_usd' => $datos['costo_usd'],
                'costo_bs' => $datos['costo_bs'],
                'activo' => true,
            ]);

            if ($datos['registrar_series']) {
                foreach ($datos['series'] as $serie) {
                    $producto->unidades()->create([
                        'sucursal_id' => $serie['sucursal_id'],
                        'numero_serie' => filled($serie['numero_serie'] ?? null)
                            ? trim((string) $serie['numero_serie'])
                            : null,
                        'estado' => ProductoUnidad::ESTADO_DISPONIBLE,
                        'observaciones' => $serie['observaciones'] ?? null,
                        'fecha_ingreso' => now(),
                        'activo' => true,
                    ]);
                }
            } else {
                foreach ($datos['distribucion_sucursales'] as $distribucion) {
                    for ($indice = 0; $indice < (int) $distribucion['cantidad']; $indice++) {
                        $producto->unidades()->create([
                            'sucursal_id' => $distribucion['sucursal_id'],
                            'numero_serie' => null,
                            'estado' => ProductoUnidad::ESTADO_DISPONIBLE,
                            'observaciones' => null,
                            'fecha_ingreso' => now(),
                            'activo' => true,
                        ]);
                    }
                }
            }

            return $producto->fresh(['marca', 'categoria', 'unidades.sucursal']);
        });

        return response()->json([
            'message' => 'Producto registrado correctamente.',
            'producto' => $this->formatearProducto($producto),
        ], 201);
    }

    public function actualizar(Request $solicitud, Producto $producto): JsonResponse
    {
        if ($respuestaAutorizacion = $this->autorizarGestionProductos($solicitud)) {
            return $respuestaAutorizacion;
        }

        if (! $producto->activo) {
            return response()->json([
                'message' => 'El producto seleccionado no se encuentra disponible para edicion.',
            ], 404);
        }

        $datos = $solicitud->validate([
            'modelo' => ['required', 'string', 'max:120'],
            'marca_id' => ['required', 'integer', Rule::exists('marcas', 'id')],
            'categoria_id' => ['required', 'integer', Rule::exists('categorias', 'id')],
            'tipo_cambio' => ['required', 'numeric', 'gt:0'],
            'precio_unitario_usd' => ['required', 'numeric', 'min:0'],
            'precio_unitario_bs' => ['required', 'numeric', 'min:0'],
            'precio_mayorista_usd' => ['nullable', 'numeric', 'min:0'],
            'precio_mayorista_bs' => ['nullable', 'numeric', 'min:0'],
            'costo_usd' => ['required', 'numeric', 'min:0'],
            'costo_bs' => ['required', 'numeric', 'min:0'],
        ]);

        $producto->forceFill([
            'modelo' => trim($datos['modelo']),
            'marca_id' => $datos['marca_id'],
            'categoria_id' => $datos['categoria_id'],
            'precio_unitario_usd' => $datos['precio_unitario_usd'],
            'precio_unitario_bs' => $datos['precio_unitario_bs'],
            'precio_mayorista_usd' => $datos['precio_mayorista_usd'] ?? $datos['precio_unitario_usd'],
            'precio_mayorista_bs' => $datos['precio_mayorista_bs'] ?? $datos['precio_unitario_bs'],
            'costo_usd' => $datos['costo_usd'],
            'costo_bs' => $datos['costo_bs'],
        ])->save();

        return response()->json([
            'message' => 'Producto actualizado correctamente.',
            'producto' => $this->formatearProducto($producto->fresh(['marca', 'categoria', 'unidades.sucursal'])),
        ]);
    }

    public function eliminar(Request $solicitud, Producto $producto): JsonResponse
    {
        if ($respuestaAutorizacion = $this->autorizarGestionProductos($solicitud)) {
            return $respuestaAutorizacion;
        }

        if (! $producto->activo) {
            return response()->json([
                'message' => 'El producto seleccionado ya fue eliminado previamente.',
            ], 422);
        }

        DB::transaction(function () use ($producto) {
            $producto->forceFill([
                'activo' => false,
            ])->save();

            $producto->unidades()->update([
                'activo' => false,
            ]);
        });

        return response()->json([
            'message' => 'Producto eliminado correctamente.',
        ]);
    }

    protected function formatearProducto(Producto $producto): array
    {
        $unidadesActivas = $producto->unidades
            ->where('activo', true)
            ->values();

        $existenciasPorSucursal = Sucursal::query()
            ->where('activa', true)
            ->orderBy('nombre')
            ->get()
            ->map(function (Sucursal $sucursal) use ($unidadesActivas) {
                $cantidad = $unidadesActivas
                    ->where('sucursal_id', $sucursal->id)
                    ->where('estado', ProductoUnidad::ESTADO_DISPONIBLE)
                    ->count();

                return [
                    'sucursal_id' => $sucursal->id,
                    'sucursal' => $sucursal->nombre,
                    'cantidad' => $cantidad,
                ];
            })
            ->values()
            ->all();

        return [
            'id' => $producto->id,
            'modelo' => $producto->modelo,
            'marca_id' => $producto->marca_id,
            'marca' => $producto->marca?->nombre,
            'categoria_id' => $producto->categoria_id,
            'categoria' => $producto->categoria?->nombre,
            'precio_unitario_usd' => (float) $producto->precio_unitario_usd,
            'precio_unitario_bs' => (float) $producto->precio_unitario_bs,
            'precio_mayorista_usd' => (float) $producto->precio_mayorista_usd,
            'precio_mayorista_bs' => (float) $producto->precio_mayorista_bs,
            'costo_usd' => (float) $producto->costo_usd,
            'costo_bs' => (float) $producto->costo_bs,
            'activo' => $producto->activo,
            'tipo_cambio_referencia' => $producto->costo_usd > 0
                ? round(((float) $producto->costo_bs / (float) $producto->costo_usd), 4)
                : null,
            'existencias_totales' => $unidadesActivas
                ->where('estado', ProductoUnidad::ESTADO_DISPONIBLE)
                ->count(),
            'existencias_por_sucursal' => $existenciasPorSucursal,
            'unidades' => $unidadesActivas->map(function (ProductoUnidad $unidad) {
                return [
                    'id' => $unidad->id,
                    'numero_serie' => $unidad->numero_serie,
                    'estado' => $unidad->estado,
                    'observaciones' => $unidad->observaciones,
                    'fecha_ingreso' => optional($unidad->fecha_ingreso)?->toDateTimeString(),
                    'sucursal_id' => $unidad->sucursal_id,
                    'sucursal' => $unidad->sucursal?->nombre,
                    'activo' => $unidad->activo,
                ];
            })->values()->all(),
        ];
    }

    protected function autorizarGestionProductos(Request $solicitud): ?JsonResponse
    {
        /** @var User $usuario */
        $usuario = $solicitud->user();

        if (! in_array($usuario->rol, [User::ROL_GERENTE, User::ROL_AUXILIAR_ADMINISTRATIVO], true)) {
            return response()->json([
                'message' => 'No tienes permiso para gestionar productos.',
            ], 403);
        }

        return null;
    }

    protected function obtenerOperacionesDetalle(Producto $producto): array
    {
        $adquisiciones = $this->obtenerOperacionesAdquisicion($producto);
        $transferencias = $this->obtenerOperacionesTransferencia($producto);

        return collect()
            ->merge($adquisiciones)
            ->merge($transferencias)
            ->sortByDesc('fecha_iso')
            ->values()
            ->all();
    }

    protected function obtenerOperacionesAdquisicion(Producto $producto): array
    {
        return $producto->unidades
            ->where('activo', true)
            ->groupBy(function (ProductoUnidad $unidad) {
                $fecha = optional($unidad->fecha_ingreso)?->format('Y-m-d H:i:s') ?? 'sin_fecha';

                return $fecha.'|'.$unidad->sucursal_id;
            })
            ->map(function ($unidades, string $clave) use ($producto) {
                /** @var \Illuminate\Support\Collection<int, ProductoUnidad> $unidades */
                [$fechaAgrupada] = explode('|', $clave);

                $primeraUnidad = $unidades->first();
                $fecha = $primeraUnidad?->fecha_ingreso;
                $cantidad = $unidades->count();
                $sucursal = $primeraUnidad?->sucursal?->nombre ?? 'Sucursal no disponible';

                return [
                    'id' => 'adquisicion-'.$producto->id.'-'.md5($clave),
                    'tipo' => 'adquisicion',
                    'fecha' => $this->formatearFechaHora($fecha),
                    'fecha_iso' => $fecha?->toIso8601String(),
                    'cantidad' => $cantidad,
                    'sucursal' => $sucursal,
                    'descripcion' => sprintf(
                        '%s: Se registraron %d unidad(es) en la sucursal %s al costo de $ %s por unidad.',
                        $this->formatearFechaHora($fecha),
                        $cantidad,
                        $sucursal,
                        number_format((float) $producto->costo_usd, 2, '.', '')
                    ),
                ];
            })
            ->values()
            ->all();
    }

    protected function obtenerOperacionesTransferencia(Producto $producto): array
    {
        return SolicitudTransferencia::query()
            ->with([
                'usuarioRevisor:id,nombre,apellido',
                'usuarioSolicitante:id,nombre,apellido',
                'sucursalOrigen:id,nombre',
                'sucursalSolicitante:id,nombre',
            ])
            ->where('producto_id', $producto->id)
            ->whereIn('estado', [
                SolicitudTransferencia::ESTADO_APROBADA,
                SolicitudTransferencia::ESTADO_APROBADA_PARCIAL,
            ])
            ->orderByDesc('fecha_respuesta')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (SolicitudTransferencia $solicitud) {
                $fecha = $solicitud->fecha_respuesta ?? $solicitud->created_at;
                $cantidad = (int) ($solicitud->cantidad_aprobada ?? 0);
                $nombreRevisor = trim(collect([
                    $solicitud->usuarioRevisor?->nombre,
                    $solicitud->usuarioRevisor?->apellido,
                ])->filter()->implode(' '));

                if ($nombreRevisor === '') {
                    $nombreRevisor = trim(collect([
                        $solicitud->usuarioSolicitante?->nombre,
                        $solicitud->usuarioSolicitante?->apellido,
                    ])->filter()->implode(' '));
                }

                $detalleRespuesta = filled($solicitud->detalle_respuesta)
                    ? ' Detalle: '.trim((string) $solicitud->detalle_respuesta).'.'
                    : '';

                return [
                    'id' => 'transferencia-'.$solicitud->id,
                    'tipo' => 'transferencia',
                    'fecha' => $this->formatearFechaHora($fecha),
                    'fecha_iso' => $fecha?->toIso8601String(),
                    'cantidad' => $cantidad,
                    'estado' => $solicitud->estado,
                    'sucursal_origen' => $solicitud->sucursalOrigen?->nombre,
                    'sucursal_destino' => $solicitud->sucursalSolicitante?->nombre,
                    'descripcion' => sprintf(
                        '%s: %s transfirio %d unidad(es) desde la sucursal %s con destino a la sucursal %s.%s',
                        $this->formatearFechaHora($fecha),
                        $nombreRevisor !== '' ? $nombreRevisor : 'Sistema',
                        $cantidad,
                        $solicitud->sucursalOrigen?->nombre ?? 'Sucursal no disponible',
                        $solicitud->sucursalSolicitante?->nombre ?? 'Sucursal no disponible',
                        $detalleRespuesta
                    ),
                ];
            })
            ->values()
            ->all();
    }

    protected function formatearFechaHora($fecha): ?string
    {
        if (! $fecha) {
            return null;
        }

        return $fecha->format('d/m/Y - H:i');
    }

    protected function formatearMarcas(iterable $marcas): array
    {
        return collect($marcas)
            ->map(fn (Marca $marca) => [
                'id' => $marca->id,
                'value' => $marca->id,
                'label' => $marca->nombre,
            ])
            ->values()
            ->all();
    }

    protected function formatearCategorias(iterable $categorias): array
    {
        return collect($categorias)
            ->map(fn (Categoria $categoria) => [
                'id' => $categoria->id,
                'value' => $categoria->id,
                'label' => $categoria->nombre,
            ])
            ->values()
            ->all();
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
}
