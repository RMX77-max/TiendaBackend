<?php

namespace App\Http\Requests;

use App\Models\Compra;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarCompraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'proveedor_id' => ['required', 'integer', Rule::exists('proveedores', 'id')],
            'fecha_pedido' => ['required', 'date'],
            'tipo_compra' => ['required', 'string', Rule::in(Compra::obtenerTiposCompraDisponibles())],
            'tipo_cambio_general' => ['required', 'numeric', 'gt:0'],
            'tiempo_entrega_dias' => ['required', 'integer', 'min:0'],
            'observaciones' => ['nullable', 'string'],
            'detalles' => ['required', 'array', 'min:1'],
            'detalles.*.producto_id' => ['nullable', 'integer', Rule::exists('productos', 'id')],
            'detalles.*.detalle_texto' => ['nullable', 'string', 'max:255'],
            'detalles.*.cantidad' => ['required', 'integer', 'min:1'],
            'detalles.*.tipo_cambio_aplicado' => ['nullable', 'numeric', 'gt:0'],
            'detalles.*.moneda_referencia' => ['nullable', 'string', Rule::in(['usd', 'bs'])],
            'detalles.*.precio_unitario_usd' => ['required', 'numeric', 'min:0'],
            'detalles.*.precio_unitario_bs' => ['required', 'numeric', 'min:0'],
            'detalles.*.observaciones' => ['nullable', 'string'],
            'abonos' => ['nullable', 'array'],
            'abonos.*.sucursal_id' => ['required_with:abonos', 'integer', Rule::exists('sucursales', 'id')],
            'abonos.*.tipo_cambio_abono' => ['required_with:abonos', 'numeric', 'gt:0'],
            'abonos.*.moneda_referencia' => ['nullable', 'string', Rule::in(['usd', 'bs'])],
            'abonos.*.abono_usd' => ['required_with:abonos', 'numeric', 'min:0'],
            'abonos.*.abono_bs' => ['required_with:abonos', 'numeric', 'min:0'],
            'abonos.*.fecha_abono' => ['nullable', 'date'],
            'abonos.*.comprobante_foto' => ['nullable', 'file', 'image', 'max:5120'],
            'abonos.*.observaciones' => ['nullable', 'string'],
            'cuotas' => ['nullable', 'array'],
            'cuotas.*.fecha_vencimiento' => ['required_with:cuotas', 'date'],
            'cuotas.*.monto_usd' => ['required_with:cuotas', 'numeric', 'min:0'],
            'cuotas.*.observaciones' => ['nullable', 'string'],
        ];
    }
}
