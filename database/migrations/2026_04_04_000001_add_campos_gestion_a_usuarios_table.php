<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $tabla) {
            $tabla->string('documento_identidad', 30)->nullable()->after('apellido');
            $tabla->string('telefono', 30)->nullable()->after('correo_electronico');
            $tabla->string('direccion', 255)->nullable()->after('telefono');
            $tabla->string('sucursal', 120)->nullable()->after('rol');
            $tabla->string('foto_factura_luz', 255)->nullable()->after('sucursal');

            $tabla->unique('documento_identidad');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $tabla) {
            $tabla->dropUnique('usuarios_documento_identidad_unique');
            $tabla->dropColumn([
                'documento_identidad',
                'telefono',
                'direccion',
                'sucursal',
                'foto_factura_luz',
            ]);
        });
    }
};
