<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_detalles', function (Blueprint $table) {
            $table->string('modelo_snapshot', 150)->nullable()->after('producto_id');
            $table->string('marca_snapshot', 100)->nullable()->after('modelo_snapshot');
            $table->string('categoria_snapshot', 100)->nullable()->after('marca_snapshot');
            $table->text('series_snapshot')->nullable()->after('categoria_snapshot');
            $table->decimal('precio_lista_usd', 14, 2)->default(0)->after('cantidad');
            $table->decimal('precio_lista_bs', 14, 2)->default(0)->after('precio_lista_usd');
            $table->decimal('precio_mayorista_usd', 14, 2)->default(0)->after('precio_lista_bs');
            $table->decimal('precio_mayorista_bs', 14, 2)->default(0)->after('precio_mayorista_usd');
        });
    }

    public function down(): void
    {
        Schema::table('venta_detalles', function (Blueprint $table) {
            $table->dropColumn([
                'modelo_snapshot',
                'marca_snapshot',
                'categoria_snapshot',
                'series_snapshot',
                'precio_lista_usd',
                'precio_lista_bs',
                'precio_mayorista_usd',
                'precio_mayorista_bs',
            ]);
        });
    }
};
