<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompraAbono extends Model
{
    use HasFactory;

    protected $table = 'compra_abonos';

    protected $fillable = [
        'compra_id',
        'sucursal_id',
        'tipo_cambio_abono',
        'moneda_referencia',
        'abono_usd',
        'abono_bs',
        'comprobante_path',
        'observaciones',
        'fecha_abono',
    ];

    protected function casts(): array
    {
        return [
            'tipo_cambio_abono' => 'decimal:6',
            'abono_usd' => 'decimal:6',
            'abono_bs' => 'decimal:6',
            'fecha_abono' => 'date',
        ];
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class, 'compra_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }
}
