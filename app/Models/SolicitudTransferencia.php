<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitudTransferencia extends Model
{
    use HasFactory;

    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_APROBADA = 'aprobada';
    public const ESTADO_APROBADA_PARCIAL = 'aprobada_parcial';
    public const ESTADO_RECHAZADA = 'rechazada';

    protected $table = 'solicitudes_transferencia';

    protected $fillable = [
        'producto_id',
        'usuario_solicitante_id',
        'sucursal_solicitante_id',
        'sucursal_origen_id',
        'cantidad_solicitada',
        'cantidad_aprobada',
        'detalle_solicitud',
        'detalle_respuesta',
        'estado',
        'usuario_revisor_id',
        'fecha_respuesta',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_solicitada' => 'integer',
            'cantidad_aprobada' => 'integer',
            'fecha_respuesta' => 'datetime',
        ];
    }

    public static function obtenerEstadosDisponibles(): array
    {
        return [
            self::ESTADO_PENDIENTE,
            self::ESTADO_APROBADA,
            self::ESTADO_APROBADA_PARCIAL,
            self::ESTADO_RECHAZADA,
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function usuarioSolicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_solicitante_id');
    }

    public function sucursalSolicitante(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_solicitante_id');
    }

    public function sucursalOrigen(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_origen_id');
    }

    public function usuarioRevisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_revisor_id');
    }
}