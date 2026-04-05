<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompraDetalle extends Model
{
    use HasFactory;

    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_PARCIAL = 'parcial';
    public const ESTADO_COMPLETO = 'completo';
    public const ESTADO_INCOMPLETO = 'incompleto';

    protected $table = 'compra_detalles';

    protected $fillable = [
        'compra_id',
        'producto_id',
        'detalle_texto',
        'cantidad_pedida',
        'cantidad_recibida_acumulada',
        'cantidad_pendiente',
        'tipo_cambio_aplicado',
        'moneda_referencia',
        'precio_unitario_usd',
        'precio_unitario_bs',
        'subtotal_usd',
        'subtotal_bs',
        'observaciones',
        'estado_linea',
    ];

    protected function casts(): array
    {
        return [
            'tipo_cambio_aplicado' => 'decimal:6',
            'precio_unitario_usd' => 'decimal:6',
            'precio_unitario_bs' => 'decimal:6',
            'subtotal_usd' => 'decimal:6',
            'subtotal_bs' => 'decimal:6',
        ];
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class, 'compra_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
