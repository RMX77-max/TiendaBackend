<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductoUnidad extends Model
{
    use HasFactory;

    public const ESTADO_DISPONIBLE = 'disponible';
    public const ESTADO_RESERVADO = 'reservado';
    public const ESTADO_EN_TRANSFERENCIA = 'en_transferencia';
    public const ESTADO_VENDIDO = 'vendido';
    public const ESTADO_INACTIVO = 'inactivo';

    protected $table = 'producto_unidades';

    protected $fillable = [
        'producto_id',
        'sucursal_id',
        'numero_serie',
        'estado',
        'observaciones',
        'fecha_ingreso',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'fecha_ingreso' => 'datetime',
            'activo' => 'boolean',
        ];
    }

    public static function obtenerEstadosDisponibles(): array
    {
        return [
            self::ESTADO_DISPONIBLE,
            self::ESTADO_RESERVADO,
            self::ESTADO_EN_TRANSFERENCIA,
            self::ESTADO_VENDIDO,
            self::ESTADO_INACTIVO,
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }
}
