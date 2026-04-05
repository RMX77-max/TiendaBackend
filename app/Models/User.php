<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $table = 'usuarios';

    public const ROL_VENDEDOR = 'vendedor';
    public const ROL_SUPERVISOR_SUCURSAL = 'supervisor_sucursal';
    public const ROL_AUXILIAR_ADMINISTRATIVO = 'auxiliar_administrativo';
    public const ROL_GERENTE = 'gerente';

    /**
     * Los atributos que pueden asignarse masivamente.
     *
     * @var list<string>
     */
    protected $fillable = [
        'nombre',
        'apellido',
        'documento_identidad',
        'nombre_usuario',
        'correo_electronico',
        'telefono',
        'direccion',
        'contrasena',
        'rol',
        'sucursal',
        'foto_factura_luz',
        'activo',
    ];

    /**
     * Los atributos que deben ocultarse en la serializacion.
     *
     * @var list<string>
     */
    protected $hidden = [
        'contrasena',
        'remember_token',
    ];

    /**
     * Convierte atributos a sus tipos correspondientes.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contrasena' => 'hashed',
            'activo' => 'boolean',
        ];
    }

    public static function obtenerRolesDisponibles(): array
    {
        return [
            ['value' => self::ROL_GERENTE, 'label' => 'Gerente'],
            ['value' => self::ROL_AUXILIAR_ADMINISTRATIVO, 'label' => 'Auxiliar administrativo'],
            ['value' => self::ROL_SUPERVISOR_SUCURSAL, 'label' => 'Supervisor'],
            ['value' => self::ROL_VENDEDOR, 'label' => 'Vendedor'],
        ];
    }

    public static function obtenerEtiquetaRol(?string $rol): ?string
    {
        return collect(self::obtenerRolesDisponibles())
            ->firstWhere('value', $rol)['label'] ?? null;
    }

    public function getAuthPassword(): string
    {
        return $this->contrasena;
    }

    protected function nombreUsuario(): Attribute
    {
        return Attribute::make(
            set: fn (?string $valor) => $valor ? trim(mb_strtolower($valor)) : null,
        );
    }

    public function tokensAcceso(): HasMany
    {
        return $this->hasMany(TokenAcceso::class, 'user_id');
    }
}
