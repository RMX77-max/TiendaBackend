<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenAcceso extends Model
{
    use HasFactory;

    protected $table = 'tokens_acceso';

    protected $fillable = [
        'user_id',
        'nombre',
        'token',
        'ultimo_uso_en',
        'expira_en',
    ];

    protected $hidden = [
        'token',
    ];

    /**
     * Convierte atributos a sus tipos correspondientes.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ultimo_uso_en' => 'datetime',
            'expira_en' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
