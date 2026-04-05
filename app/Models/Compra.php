<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Compra extends Model
{
    use HasFactory;

    public const TIPO_CONTADO = 'contado';
    public const TIPO_PEDIDO = 'pedido';
    public const TIPO_CREDITO = 'a_credito';

    public const ESTADO_BORRADOR = 'borrador';
    public const ESTADO_REGISTRADO = 'registrado';
    public const ESTADO_EN_TRANSITO = 'en_transito';
    public const ESTADO_PENDIENTE_RECEPCION = 'pendiente_recepcion';
    public const ESTADO_PENDIENTE_INGRESO_INVENTARIO = 'pendiente_ingreso_inventario';
    public const ESTADO_PARCIAL = 'parcial';
    public const ESTADO_COMPLETADO = 'completado';
    public const ESTADO_INCOMPLETO = 'incompleto';
    public const ESTADO_CERRADO = 'cerrado';

    protected $table = 'compras';

    protected $fillable = [
        'proveedor_id',
        'fecha_pedido',
        'tipo_compra',
        'nro_pedido',
        'tipo_cambio_general',
        'tiempo_entrega_dias',
        'estado',
        'total_productos_usd',
        'total_productos_bs',
        'total_abonos_usd',
        'total_abonos_bs',
        'saldo_pendiente_usd',
        'saldo_pendiente_bs',
        'total_guias_usd',
        'total_guias_bs',
        'devolucion_pendiente_usd',
        'devolucion_pendiente_bs',
        'observaciones',
        'fecha_cierre',
        'cerrado_forzado',
    ];

    protected function casts(): array
    {
        return [
            'fecha_pedido' => 'date',
            'tipo_cambio_general' => 'decimal:6',
            'total_productos_usd' => 'decimal:4',
            'total_productos_bs' => 'decimal:4',
            'total_abonos_usd' => 'decimal:4',
            'total_abonos_bs' => 'decimal:4',
            'saldo_pendiente_usd' => 'decimal:4',
            'saldo_pendiente_bs' => 'decimal:4',
            'total_guias_usd' => 'decimal:4',
            'total_guias_bs' => 'decimal:4',
            'devolucion_pendiente_usd' => 'decimal:4',
            'devolucion_pendiente_bs' => 'decimal:4',
            'fecha_cierre' => 'datetime',
            'cerrado_forzado' => 'boolean',
        ];
    }

    public static function obtenerTiposCompraDisponibles(): array
    {
        return [
            self::TIPO_CONTADO,
            self::TIPO_PEDIDO,
            self::TIPO_CREDITO,
        ];
    }

    public static function obtenerEstadosDisponibles(): array
    {
        return [
            self::ESTADO_BORRADOR,
            self::ESTADO_REGISTRADO,
            self::ESTADO_EN_TRANSITO,
            self::ESTADO_PENDIENTE_RECEPCION,
            self::ESTADO_PENDIENTE_INGRESO_INVENTARIO,
            self::ESTADO_PARCIAL,
            self::ESTADO_COMPLETADO,
            self::ESTADO_INCOMPLETO,
            self::ESTADO_CERRADO,
        ];
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(CompraDetalle::class, 'compra_id');
    }

    public function abonos(): HasMany
    {
        return $this->hasMany(CompraAbono::class, 'compra_id');
    }

    public function cuotas(): HasMany
    {
        return $this->hasMany(CompraCuota::class, 'compra_id');
    }

    public function guias(): HasMany
    {
        return $this->hasMany(CompraGuia::class, 'compra_id');
    }

    public function recepciones(): HasMany
    {
        return $this->hasMany(CompraRecepcion::class, 'compra_id');
    }
}
