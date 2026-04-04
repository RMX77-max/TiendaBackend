<?php

use App\Http\Controllers\Api\ControladorAutenticacion;
use App\Http\Controllers\Api\ControladorUsuarios;
use Illuminate\Support\Facades\Route;

Route::prefix('autenticacion')->group(function () {
    Route::post('/iniciar-sesion', [ControladorAutenticacion::class, 'iniciarSesion']);

    Route::middleware('autenticar.token')->group(function () {
        Route::get('/perfil', [ControladorAutenticacion::class, 'perfil']);
        Route::post('/cerrar-sesion', [ControladorAutenticacion::class, 'cerrarSesion']);
    });
});

Route::middleware('autenticar.token')->prefix('usuarios')->group(function () {
    Route::get('/formulario', [ControladorUsuarios::class, 'obtenerFormulario']);
    Route::get('/', [ControladorUsuarios::class, 'listar']);
    Route::post('/', [ControladorUsuarios::class, 'registrar']);
    Route::patch('/{usuario}/estado', [ControladorUsuarios::class, 'cambiarEstado']);
});
