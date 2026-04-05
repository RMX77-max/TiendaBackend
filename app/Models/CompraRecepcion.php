<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompraRecepcion extends Model
{
    use HasFactory;

    public const ESTADO_PARCIAL = 'parcial';
    public const ESTADO_COMPLETA = 'completa';

    protected $table = 'compra_recepciones';

    protected $fillable = [
        'compra_id',
        'compra_guia_id',
        'fecha_recepcion',
        'estado_recepcion',
        'observaciones',
        'ingresado_inventario',
        'fecha_ingreso_inventario',
    ];

    protected function casts(): array
    {
        return [
            'fecha_recepcion' => 'date',
            'ingresado_inventario' => 'boolean',
            'fecha_ingreso_inventario' => 'datetime',
        ];
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class, 'compra_id');
    }

    public function guia(): BelongsTo
    {
        return $this->belongsTo(CompraGuia::class, 'compra_guia_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(CompraRecepcionDetalle::class, 'recepcion_id');
    }
}
