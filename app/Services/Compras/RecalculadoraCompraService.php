<?php

namespace App\Services\Compras;

use App\Models\Compra;
use App\Models\CompraDetalle;
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
        $compra->loadMissing(['detalles', 'abonos']);

        $totalProductosUsd = $this->redondear($compra->detalles->sum('subtotal_usd'), 4);
        $totalProductosBs = $this->redondear($compra->detalles->sum('subtotal_bs'), 4);
        $totalAbonosUsd = $this->redondear($compra->abonos->sum('abono_usd'), 4);
        $totalAbonosBs = $this->redondear($compra->abonos->sum('abono_bs'), 4);
        $saldoPendienteUsd = max(0, $this->redondear($totalProductosUsd - $totalAbonosUsd, 4));
        $tipoCambioPromedioPedido = $totalProductosUsd > 0
            ? ($totalProductosBs / $totalProductosUsd)
            : 0;
        $saldoPendienteBs = max(0, $this->redondear($saldoPendienteUsd * $tipoCambioPromedioPedido, 4));

        $compra->forceFill([
            'total_productos_usd' => $totalProductosUsd,
            'total_productos_bs' => $totalProductosBs,
            'total_abonos_usd' => $totalAbonosUsd,
            'total_abonos_bs' => $totalAbonosBs,
            'saldo_pendiente_usd' => $saldoPendienteUsd,
            'saldo_pendiente_bs' => $saldoPendienteBs,
            'devolucion_pendiente_usd' => 0,
            'devolucion_pendiente_bs' => 0,
            'estado' => $this->determinarEstadoCompra($compra),
        ])->save();

        return $compra->fresh(['proveedor', 'detalles.producto', 'abonos.sucursal']);
    }

    public function determinarEstadoCompra(Compra $compra): string
    {
        if ((int) $compra->tiempo_entrega_dias === 0) {
            return Compra::ESTADO_PENDIENTE_INGRESO_INVENTARIO;
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

    protected function redondear(float|int|string $valor, int $precision): float
    {
        return round((float) $valor, $precision);
    }
}
