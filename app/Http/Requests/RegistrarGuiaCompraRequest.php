<?php

namespace App\Http\Requests;

use App\Models\CompraGuia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarGuiaCompraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha_registro' => ['required', 'date'],
            'monto_bs' => ['required', 'numeric', 'min:0'],
            'estado' => ['required', 'string', Rule::in([
                CompraGuia::ESTADO_EN_TRANSITO,
                CompraGuia::ESTADO_RECOGIDO,
            ])],
            'pagado' => ['nullable', 'boolean'],
            'observaciones' => ['nullable', 'string'],
        ];
    }
}
