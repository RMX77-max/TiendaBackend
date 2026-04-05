<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('nombre', 150)->unique();
            $tabla->string('contacto', 120)->nullable();
            $tabla->string('telefono', 40)->nullable();
            $tabla->string('correo', 120)->nullable();
            $tabla->string('direccion', 255)->nullable();
            $tabla->text('observaciones')->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedores');
    }
};
