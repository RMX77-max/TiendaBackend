<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaDetalle extends Model
{
    use HasFactory;

    protected $table = 'venta_detalles';

    protected $fillable = [
        'venta_id',
        'producto_id',
        'modelo_snapshot',
        'marca_snapshot',
        'categoria_snapshot',
        'series_snapshot',
        'cantidad',
        'precio_lista_usd',
        'precio_lista_bs',
        'precio_mayorista_usd',
        'precio_mayorista_bs',
        'precio_unitario_usd',
        'precio_unitario_bs',
        'subtotal_usd',
        'subtotal_bs',
    ];

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
