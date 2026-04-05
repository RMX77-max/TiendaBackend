<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarPagoCreditoCompraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sucursal_id' => ['required', 'integer', Rule::exists('sucursales', 'id')],
            'fecha_abono' => ['required', 'date'],
            'tipo_cambio_abono' => ['required', 'numeric', 'gt:0'],
            'moneda_referencia' => ['nullable', 'string', Rule::in(['usd', 'bs'])],
            'abono_usd' => ['required', 'numeric', 'min:0'],
            'abono_bs' => ['required', 'numeric', 'min:0'],
            'observaciones' => ['nullable', 'string'],
        ];
    }
}
