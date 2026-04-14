<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Caja extends Model
{
    use HasFactory;

    protected $table = 'cajas';

    public const MONEDA_BS = 'bs';
    public const MONEDA_USD = 'usd';

    public const METODO_EFECTIVO = 'efectivo';
    public const METODO_QR = 'qr';
    public const METODO_TRANSFERENCIA = 'transferencia';
    public const METODO_OTRO = 'otro';

    protected $fillable = [
        'sucursal_id',
        'nombre',
        'codigo',
        'tipo_moneda',
        'metodo_base',
        'activa',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }

    public static function obtenerMonedasDisponibles(): array
    {
        return [
            ['value' => self::MONEDA_BS, 'label' => 'Bolivianos'],
            ['value' => self::MONEDA_USD, 'label' => 'Dolares'],
        ];
    }

    public static function obtenerMetodosDisponibles(): array
    {
        return [
            ['value' => self::METODO_EFECTIVO, 'label' => 'Efectivo'],
            ['value' => self::METODO_QR, 'label' => 'QR'],
            ['value' => self::METODO_TRANSFERENCIA, 'label' => 'Transferencia'],
            ['value' => self::METODO_OTRO, 'label' => 'Otro'],
        ];
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoCaja::class, 'caja_id');
    }
}
