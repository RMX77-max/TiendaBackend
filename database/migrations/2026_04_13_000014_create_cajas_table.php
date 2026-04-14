<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cajas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnDelete();
            $table->string('nombre', 120);
            $table->string('codigo', 80)->nullable();
            $table->string('tipo_moneda', 10);
            $table->string('metodo_base', 30);
            $table->boolean('activa')->default(true);
            $table->string('observaciones', 255)->nullable();
            $table->timestamps();

            $table->unique(['sucursal_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cajas');
    }
};
