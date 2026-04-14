<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->string('tipo_item', 100)->nullable()->after('tipo_cambio');
            $table->string('cliente_nombre', 150)->nullable()->after('tipo_item');
            $table->string('cliente_telefono', 40)->nullable()->after('cliente_nombre');
            $table->date('cliente_fecha_nacimiento')->nullable()->after('cliente_telefono');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn([
                'tipo_item',
                'cliente_nombre',
                'cliente_telefono',
                'cliente_fecha_nacimiento',
            ]);
        });
    }
};
