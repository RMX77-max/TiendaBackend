<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompraCuota extends Model
{
    use HasFactory;

    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_PARCIAL = 'parcial';
    public const ESTADO_PAGADA = 'pagada';

    protected $table = 'compra_cuotas';

    protected $fillable = [
        'compra_id',
        'nro_cuota',
        'fecha_vencimiento',
        'tipo_cambio_referencia',
        'monto_usd',
        'monto_bs',
        'saldo_pendiente_usd',
        'saldo_pendiente_bs',
        'estado',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha_vencimiento' => 'date',
            'tipo_cambio_referencia' => 'decimal:6',
            'monto_usd' => 'decimal:6',
            'monto_bs' => 'decimal:6',
            'saldo_pendiente_usd' => 'decimal:6',
            'saldo_pendiente_bs' => 'decimal:6',
        ];
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class, 'compra_id');
    }
}
