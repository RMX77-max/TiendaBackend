<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compra_abonos', function (Blueprint $tabla) {
            $tabla->decimal('tipo_cambio_referencia', 14, 6)
                ->nullable()
                ->after('tipo_cambio_abono');
        });

        DB::table('compra_abonos')
            ->join('compras', 'compras.id', '=', 'compra_abonos.compra_id')
            ->update([
                'compra_abonos.tipo_cambio_referencia' => DB::raw('compras.tipo_cambio_general'),
            ]);
    }

    public function down(): void
    {
        Schema::table('compra_abonos', function (Blueprint $tabla) {
            $tabla->dropColumn('tipo_cambio_referencia');
        });
    }
};
