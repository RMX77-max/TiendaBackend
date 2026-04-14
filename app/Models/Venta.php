<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venta extends Model
{
    use HasFactory;

    protected $table = 'ventas';

    public const ESTADO_CONFIRMADA = 'confirmada';

    protected $fillable = [
        'nro_venta',
        'sucursal_id',
        'usuario_id',
        'caja_id',
        'estado',
        'moneda',
        'tipo_cambio',
        'tipo_item',
        'cliente_nombre',
        'cliente_telefono',
        'cliente_fecha_nacimiento',
        'subtotal_usd',
        'subtotal_bs',
        'total_usd',
        'total_bs',
        'observaciones',
        'fecha_venta',
    ];

    protected function casts(): array
    {
        return [
            'fecha_venta' => 'datetime',
            'cliente_fecha_nacimiento' => 'date',
        ];
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(VentaDetalle::class, 'venta_id');
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class, 'caja_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
