<?php

use App\Http\Controllers\Api\ControladorAutenticacion;
use App\Http\Controllers\Api\ControladorCatalogos;
use App\Http\Controllers\Api\ControladorCompras;
use App\Http\Controllers\Api\ControladorProductos;
use App\Http\Controllers\Api\ControladorTransferencias;
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
    Route::patch('/{usuario}/sucursal', [ControladorUsuarios::class, 'actualizarSucursal']);
    Route::patch('/{usuario}/estado', [ControladorUsuarios::class, 'cambiarEstado']);
});

Route::middleware('autenticar.token')->prefix('catalogos')->group(function () {
    Route::get('/productos', [ControladorCatalogos::class, 'obtenerCatalogosProductos']);
    Route::get('/sucursales', [ControladorCatalogos::class, 'listarSucursales']);
    Route::post('/marcas', [ControladorCatalogos::class, 'registrarMarca']);
    Route::post('/categorias', [ControladorCatalogos::class, 'registrarCategoria']);
});

Route::middleware('autenticar.token')->prefix('productos')->group(function () {
    Route::get('/formulario', [ControladorProductos::class, 'obtenerFormulario']);
    Route::get('/', [ControladorProductos::class, 'listar']);
    Route::get('/{producto}/detalle', [ControladorProductos::class, 'detalle']);
    Route::post('/', [ControladorProductos::class, 'registrar']);
    Route::put('/{producto}', [ControladorProductos::class, 'actualizar']);
    Route::delete('/{producto}', [ControladorProductos::class, 'eliminar']);
});

Route::middleware('autenticar.token')->prefix('compras')->group(function () {
    Route::get('/formulario', [ControladorCompras::class, 'obtenerFormulario']);
    Route::get('/', [ControladorCompras::class, 'listar']);
    Route::post('/', [ControladorCompras::class, 'registrar']);
    Route::post('/proveedores', [ControladorCompras::class, 'registrarProveedor']);
    Route::get('/{compra}', [ControladorCompras::class, 'detalle']);
    Route::put('/{compra}', [ControladorCompras::class, 'actualizar']);
    Route::post('/{compra}/pagos-credito', [ControladorCompras::class, 'registrarPagoCredito']);
    Route::post('/{compra}/guias', [ControladorCompras::class, 'registrarGuia']);
    Route::post('/{compra}/recepciones', [ControladorCompras::class, 'registrarRecepcion']);
    Route::post('/recepciones/{recepcion}/ingresar-inventario', [ControladorCompras::class, 'ingresarRecepcionInventario']);
    Route::patch('/{compra}/cerrar-incompleto', [ControladorCompras::class, 'cerrarIncompleto']);
    Route::put('/guias/{guia}', [ControladorCompras::class, 'actualizarGuia']);
});

Route::middleware('autenticar.token')->prefix('transferencias')->group(function () {
    Route::get('/solicitudes', [ControladorTransferencias::class, 'listarSolicitudes']);
    Route::post('/solicitudes', [ControladorTransferencias::class, 'registrarSolicitud']);
    Route::post('/directa', [ControladorTransferencias::class, 'registrarTransferenciaDirecta']);
    Route::patch('/solicitudes/{solicitudTransferencia}/responder', [ControladorTransferencias::class, 'responderSolicitud']);
});
