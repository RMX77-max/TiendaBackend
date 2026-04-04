<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ejecutar la migración.
     */
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('nombre', 50);
            $tabla->string('apellido', 50)->nullable();
            $tabla->string('nombre_usuario', 50)->unique();
            $tabla->string('correo_electronico', 100)->unique();
            $tabla->string('contrasena');
            $tabla->enum('rol', [
                'vendedor',
                'supervisor_sucursal',
                'auxiliar_administrativo',
                'gerente',
            ])->default('vendedor');
            $tabla->boolean('activo')->default(true);
            $tabla->rememberToken();
            $tabla->timestamps();
        });

        Schema::create('sesiones', function (Blueprint $tabla) {
            $tabla->string('id')->primary();
            $tabla->foreignId('usuario_id')->nullable()->index();
            $tabla->string('direccion_ip', 45)->nullable();
            $tabla->text('agente_usuario')->nullable();
            $tabla->longText('carga');
            $tabla->integer('ultima_actividad')->index();
        });
    }

    /**
     * Revertir la migración.
     */
    public function down(): void
    {
        Schema::dropIfExists('usuarios');
        Schema::dropIfExists('sesiones');
    }
};
