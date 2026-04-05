<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompraRecepcionDetalle extends Model
{
    use HasFactory;

    protected $table = 'compra_recepcion_detalles';

    protected $fillable = [
        'recepcion_id',
        'compra_detalle_id',
        'cantidad_recibida',
        'observaciones',
    ];

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(CompraRecepcion::class, 'recepcion_id');
    }

    public function compraDetalle(): BelongsTo
    {
        return $this->belongsTo(CompraDetalle::class, 'compra_detalle_id');
    }
}
