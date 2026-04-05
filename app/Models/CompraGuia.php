<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompraGuia extends Model
{
    use HasFactory;

    public const ESTADO_EN_TRANSITO = 'en_transito';
    public const ESTADO_RECOGIDO = 'recogido';

    protected $table = 'compra_guias';

    protected $fillable = [
        'compra_id',
        'foto_path',
        'fecha_registro',
        'monto_bs',
        'estado',
        'pagado',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha_registro' => 'date',
            'monto_bs' => 'decimal:4',
            'pagado' => 'boolean',
        ];
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class, 'compra_id');
    }

    public function recepciones(): HasMany
    {
        return $this->hasMany(CompraRecepcion::class, 'compra_guia_id');
    }
}
