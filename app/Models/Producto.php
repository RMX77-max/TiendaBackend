<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Producto extends Model
{
    use HasFactory;

    protected $table = 'productos';

    protected $fillable = [
        'modelo',
        'marca_id',
        'categoria_id',
        'precio_unitario_usd',
        'precio_unitario_bs',
        'precio_mayorista_usd',
        'precio_mayorista_bs',
        'costo_usd',
        'costo_bs',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'precio_unitario_usd' => 'decimal:2',
            'precio_unitario_bs' => 'decimal:2',
            'precio_mayorista_usd' => 'decimal:2',
            'precio_mayorista_bs' => 'decimal:2',
            'costo_usd' => 'decimal:2',
            'costo_bs' => 'decimal:2',
            'activo' => 'boolean',
        ];
    }

    public function marca(): BelongsTo
    {
        return $this->belongsTo(Marca::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function unidades(): HasMany
    {
        return $this->hasMany(ProductoUnidad::class, 'producto_id');
    }
}
