<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * La contrasena base utilizada por la fabrica.
     */
    protected static ?string $contrasena = null;

    /**
     * Define el estado por defecto del modelo.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->firstName(),
            'apellido' => fake()->lastName(),
            'documento_identidad' => fake()->unique()->numerify('##########'),
            'nombre_usuario' => fake()->unique()->userName(),
            'correo_electronico' => fake()->unique()->safeEmail(),
            'telefono' => fake()->numerify('7#######'),
            'direccion' => fake()->address(),
            'contrasena' => static::$contrasena ??= Hash::make('password'),
            'rol' => fake()->randomElement([
                User::ROL_VENDEDOR,
                User::ROL_SUPERVISOR_SUCURSAL,
                User::ROL_AUXILIAR_ADMINISTRATIVO,
                User::ROL_GERENTE,
            ]),
            'sucursal' => fake()->randomElement(User::SUCURSALES),
            'foto_factura_luz' => null,
            'activo' => true,
            'remember_token' => Str::random(10),
        ];
    }
}
