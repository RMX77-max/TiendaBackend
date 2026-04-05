<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarRecepcionCompraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'compra_guia_id' => ['nullable', 'integer', Rule::exists('compra_guias', 'id')],
            'fecha_recepcion' => ['required', 'date'],
            'observaciones' => ['nullable', 'string'],
            'detalles' => ['required', 'array', 'min:1'],
            'detalles.*.compra_detalle_id' => ['required', 'integer', Rule::exists('compra_detalles', 'id')],
            'detalles.*.cantidad_recibida' => ['required', 'integer', 'min:1'],
            'detalles.*.observaciones' => ['nullable', 'string'],
        ];
    }
}
