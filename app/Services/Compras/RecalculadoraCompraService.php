<?php

namespace App\Services\Compras;

use App\Models\Compra;
use App\Models\CompraDetalle;
use App\Models\CompraCuota;
use App\Models\CompraGuia;
use App\Models\Producto;
use Illuminate\Support\Collection;

class RecalculadoraCompraService
{
    public function normalizarDetalles(iterable $detalles, float $tipoCambioGeneral): Collection
    {
        return collect($detalles)
            ->map(function (array $detalle) use ($tipoCambioGeneral) {
                $tipoCambio = $this->redondear($detalle['tipo_cambio_aplicado'] ?? $tipoCambioGeneral, 6);
                $tipoCambio = $tipoCambio > 0 ? $tipoCambio : $this->redondear($tipoCambioGeneral, 6);
                $monedaReferencia = $detalle['moneda_referencia'] ?? 'usd';
                $cantidadPedida = (int) ($detalle['cantidad'] ?? 0);
                $precioUsdIngresado = $this->redondear($detalle['precio_unitario_usd'] ?? 0, 6);
                $precioBsIngresado = $this->redondear($detalle['precio_unitario_bs'] ?? 0, 6);

                if ($monedaReferencia === 'bs') {
                    $precioUnitarioBs = $precioBsIngresado;
                    $precioUnitarioUsd = $tipoCambio > 0
                        ? $this->redondear($precioUnitarioBs / $tipoCambio, 6)
                        : 0;
                } else {
                    $monedaReferencia = 'usd';
                    $precioUnitarioUsd = $precioUsdIngresado;
                    $precioUnitarioBs = $this->redondear($precioUnitarioUsd * $tipoCambio, 6);
                }

                $detalleTexto = trim((string) ($detalle['detalle_texto'] ?? ''));

                if ($detalleTexto === '' && ! empty($detalle['producto_id'])) {
                    $producto = Producto::query()->select('modelo')->find($detalle['producto_id']);
                    $detalleTexto = $producto?->modelo ?? '';
                }

                $cantidadRecibida = (int) ($detalle['cantidad_recibida_acumulada'] ?? 0);

                return [
                    'producto_id' => $detalle['producto_id'] ?? null,
                    'detalle_texto' => $detalleTexto,
                    'cantidad_pedida' => $cantidadPedida,
                    'cantidad_recibida_acumulada' => $cantidadRecibida,
                    'cantidad_pendiente' => max(0, $cantidadPedida - $cantidadRecibida),
                    'tipo_cambio_aplicado' => $tipoCambio,
                    'moneda_referencia' => $monedaReferencia,
                    'precio_unitario_usd' => $precioUnitarioUsd,
                    'precio_unitario_bs' => $precioUnitarioBs,
                    'subtotal_usd' => $this->redondear($cantidadPedida * $precioUnitarioUsd, 6),
                    'subtotal_bs' => $this->redondear($cantidadPedida * $precioUnitarioBs, 6),
                    'observaciones' => $detalle['observaciones'] ?? null,
                    'estado_linea' => $this->determinarEstadoLinea($cantidadPedida, $cantidadRecibida),
                ];
            })
            ->values();
    }

    public function recalcularCompra(Compra $compra): Compra
    {
        $compra->loadMissing(['detalles', 'abonos', 'cuotas', 'guias', 'recepciones']);

        $totalProductosUsd = $this->redondear($compra->detalles->sum('subtotal_usd'), 4);
        $totalProductosBs = $this->redondear($compra->detalles->sum('subtotal_bs'), 4);
        $totalAbonosUsd = $this->redondear($compra->abonos->sum('abono_usd'), 4);
        $totalAbonosBs = $this->redondear($compra->abonos->sum('abono_bs'), 4);
        $totalGuiasBs = $this->redondear($compra->guias->sum('monto_bs'), 4);
        $saldoPendienteUsd = max(0, $this->redondear($totalProductosUsd - $totalAbonosUsd, 4));
        $tipoCambioPromedioPedido = $totalProductosUsd > 0
            ? ($totalProductosBs / $totalProductosUsd)
            : 0;
        $saldoPendienteBs = max(0, $this->redondear($saldoPendienteUsd * $tipoCambioPromedioPedido, 4));
        $estado = $this->determinarEstadoCompra($compra);
        [$devolucionPendienteUsd, $devolucionPendienteBs] = $this->calcularDevolucionPendiente($compra, $estado);

        $compra->forceFill([
            'total_productos_usd' => $totalProductosUsd,
            'total_productos_bs' => $totalProductosBs,
            'total_abonos_usd' => $totalAbonosUsd,
            'total_abonos_bs' => $totalAbonosBs,
            'saldo_pendiente_usd' => $saldoPendienteUsd,
            'saldo_pendiente_bs' => $saldoPendienteBs,
            'total_guias_usd' => 0,
            'total_guias_bs' => $totalGuiasBs,
            'devolucion_pendiente_usd' => $devolucionPendienteUsd,
            'devolucion_pendiente_bs' => $devolucionPendienteBs,
            'estado' => $estado,
        ])->save();

        return $compra->fresh(['proveedor', 'detalles.producto', 'abonos.sucursal', 'cuotas', 'guias', 'recepciones.detalles.compraDetalle']);
    }

    public function determinarEstadoCompra(Compra $compra): string
    {
        $compra->loadMissing(['detalles', 'guias', 'recepciones']);

        if ($compra->estado === Compra::ESTADO_CERRADO) {
            return Compra::ESTADO_CERRADO;
        }

        if ($compra->estado === Compra::ESTADO_INCOMPLETO) {
            return Compra::ESTADO_INCOMPLETO;
        }

        $tieneDetalles = $compra->detalles->isNotEmpty();
        $totalPendiente = (int) $compra->detalles->sum('cantidad_pendiente');
        $totalRecibido = (int) $compra->detalles->sum('cantidad_recibida_acumulada');
        $tieneRecepciones = $compra->recepciones->isNotEmpty();
        $todasLasRecepcionesIngresadas = $tieneRecepciones &&
            $compra->recepciones->every(fn ($recepcion) => $recepcion->ingresado_inventario === true);

        if ($tieneDetalles && $totalPendiente === 0) {
            return $todasLasRecepcionesIngresadas
                ? Compra::ESTADO_COMPLETADO
                : Compra::ESTADO_PENDIENTE_INGRESO_INVENTARIO;
        }

        if ($totalRecibido > 0) {
            return Compra::ESTADO_PARCIAL;
        }

        if ((int) $compra->tiempo_entrega_dias === 0) {
            return Compra::ESTADO_PENDIENTE_INGRESO_INVENTARIO;
        }

        if ($compra->guias->contains(fn (CompraGuia $guia) => $guia->estado === CompraGuia::ESTADO_RECOGIDO)) {
            return Compra::ESTADO_PENDIENTE_RECEPCION;
        }

        if ($compra->guias->contains(fn (CompraGuia $guia) => $guia->estado === CompraGuia::ESTADO_EN_TRANSITO)) {
            return Compra::ESTADO_EN_TRANSITO;
        }

        return Compra::ESTADO_REGISTRADO;
    }

    public function determinarEstadoLinea(int $cantidadPedida, int $cantidadRecibida): string
    {
        if ($cantidadRecibida <= 0) {
            return CompraDetalle::ESTADO_PENDIENTE;
        }

        if ($cantidadRecibida >= $cantidadPedida) {
            return CompraDetalle::ESTADO_COMPLETO;
        }

        return CompraDetalle::ESTADO_PARCIAL;
    }

    public function normalizarAbonos(iterable $abonos, float $tipoCambioGeneral): Collection
    {
        return collect($abonos)
            ->map(function (array $abono) use ($tipoCambioGeneral) {
                $tipoCambio = $this->redondear($abono['tipo_cambio_abono'] ?? $tipoCambioGeneral, 6);
                $tipoCambio = $tipoCambio > 0 ? $tipoCambio : $this->redondear($tipoCambioGeneral, 6);
                $monedaReferencia = $abono['moneda_referencia'] ?? 'usd';
                $abonoUsdIngresado = $this->redondear($abono['abono_usd'] ?? 0, 6);
                $abonoBsIngresado = $this->redondear($abono['abono_bs'] ?? 0, 6);

                if ($monedaReferencia === 'bs') {
                    $abonoBs = $abonoBsIngresado;
                    $abonoUsd = $tipoCambio > 0
                        ? $this->redondear($abonoBs / $tipoCambio, 6)
                        : 0;
                } else {
                    $monedaReferencia = 'usd';
                    $abonoUsd = $abonoUsdIngresado;
                    $abonoBs = $this->redondear($abonoUsd * $tipoCambio, 6);
                }

                return [
                    'sucursal_id' => $abono['sucursal_id'],
                    'tipo_cambio_abono' => $tipoCambio,
                    'moneda_referencia' => $monedaReferencia,
                    'abono_usd' => $abonoUsd,
                    'abono_bs' => $abonoBs,
                    'observaciones' => $abono['observaciones'] ?? null,
                    'fecha_abono' => $abono['fecha_abono'] ?? null,
                    'comprobante_path' => null,
                ];
            })
            ->values();
    }

    public function normalizarCuotas(iterable $cuotas, float $totalProductosUsd, float $totalProductosBs): Collection
    {
        $tipoCambioPromedioPedido = $totalProductosUsd > 0
            ? ($totalProductosBs / $totalProductosUsd)
            : 0;

        return collect($cuotas)
            ->values()
            ->map(function (array $cuota, int $indice) use ($tipoCambioPromedioPedido) {
                $montoUsd = $this->redondear($cuota['monto_usd'] ?? 0, 6);
                $montoBs = $this->redondear($montoUsd * $tipoCambioPromedioPedido, 6);

                return [
                    'nro_cuota' => $indice + 1,
                    'fecha_vencimiento' => $cuota['fecha_vencimiento'],
                    'tipo_cambio_referencia' => $this->redondear($tipoCambioPromedioPedido, 6),
                    'monto_usd' => $montoUsd,
                    'monto_bs' => $montoBs,
                    'saldo_pendiente_usd' => $montoUsd,
                    'saldo_pendiente_bs' => $montoBs,
                    'estado' => CompraCuota::ESTADO_PENDIENTE,
                    'observaciones' => $cuota['observaciones'] ?? null,
                ];
            });
    }

    protected function calcularDevolucionPendiente(Compra $compra, string $estado): array
    {
        if (! in_array($estado, [Compra::ESTADO_INCOMPLETO, Compra::ESTADO_CERRADO], true)) {
            return [0, 0];
        }

        $devolucionUsd = $this->redondear(
            $compra->detalles->sum(fn (CompraDetalle $detalle) => $detalle->cantidad_pendiente * (float) $detalle->precio_unitario_usd),
            4
        );
        $devolucionBs = $this->redondear(
            $compra->detalles->sum(fn (CompraDetalle $detalle) => $detalle->cantidad_pendiente * (float) $detalle->precio_unitario_bs),
            4
        );

        return [$devolucionUsd, $devolucionBs];
    }

    protected function redondear(float|int|string $valor, int $precision): float
    {
        return round((float) $valor, $precision);
    }
}
