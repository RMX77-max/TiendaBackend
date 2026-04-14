<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoCaja extends Model
{
    use HasFactory;

    protected $table = 'movimientos_caja';

    public const TIPO_INGRESO = 'ingreso';
    public const TIPO_EGRESO = 'egreso';
    public const TIPO_AJUSTE = 'ajuste';

    protected $fillable = [
        'caja_id',
        'sucursal_id',
        'usuario_id',
        'tipo_movimiento',
        'monto',
        'moneda',
        'tipo_cambio',
        'concepto',
        'referencia_tipo',
        'referencia_id',
        'detalle',
        'fecha_movimiento',
        'saldo_resultante',
    ];

    protected function casts(): array
    {
        return [
            'fecha_movimiento' => 'datetime',
        ];
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
