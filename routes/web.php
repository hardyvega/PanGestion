<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\AdministrationController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\CashController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PersonnelController;
use App\Http\Controllers\ProductionController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('root');

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.store');

Route::get('/inicio', [DashboardController::class, 'index'])
    ->middleware('auth')
    ->name('inicio');

Route::get('/articulos', [ArticleController::class, 'index'])->middleware('auth')->name('articles.index');
Route::get('/articulos/crear', [ArticleController::class, 'create'])->middleware('auth')->name('articles.create');
Route::post('/articulos', [ArticleController::class, 'store'])->middleware('auth')->name('articles.store');
Route::get('/articulos/{articulo}', [ArticleController::class, 'show'])->middleware('auth')->name('articles.show');
Route::get('/articulos/{articulo}/editar', [ArticleController::class, 'edit'])->middleware('auth')->name('articles.edit');
Route::put('/articulos/{articulo}', [ArticleController::class, 'update'])->middleware('auth')->name('articles.update');
Route::post('/articulos/{articulo}/activar', [ArticleController::class, 'activate'])->middleware('auth')->name('articles.activate');
Route::post('/articulos/{articulo}/desactivar', [ArticleController::class, 'deactivate'])->middleware('auth')->name('articles.deactivate');
Route::post('/articulos/{articulo}/precios', [ArticleController::class, 'updatePrice'])->middleware('auth')->name('articles.prices.store');

Route::get('/proveedores', [SupplierController::class, 'index'])->middleware('auth')->name('suppliers.index');
Route::get('/proveedores/crear', [SupplierController::class, 'create'])->middleware('auth')->name('suppliers.create');
Route::post('/proveedores', [SupplierController::class, 'store'])->middleware('auth')->name('suppliers.store');
Route::get('/proveedores/{proveedor}', [SupplierController::class, 'show'])->middleware('auth')->name('suppliers.show');
Route::get('/proveedores/{proveedor}/editar', [SupplierController::class, 'edit'])->middleware('auth')->name('suppliers.edit');
Route::put('/proveedores/{proveedor}', [SupplierController::class, 'update'])->middleware('auth')->name('suppliers.update');
Route::post('/proveedores/{proveedor}/activar', [SupplierController::class, 'activate'])->middleware('auth')->name('suppliers.activate');
Route::post('/proveedores/{proveedor}/desactivar', [SupplierController::class, 'deactivate'])->middleware('auth')->name('suppliers.deactivate');
Route::post('/proveedores/{proveedor}/articulos', [SupplierController::class, 'attach'])->middleware('auth')->name('suppliers.articles.attach');
Route::delete('/proveedores/{proveedor}/articulos/{articulo}', [SupplierController::class, 'detach'])->middleware('auth')->name('suppliers.articles.detach');

Route::get('/inventario', [InventoryController::class, 'index'])->middleware('auth')->name('inventory.index');
Route::get('/inventario/articulos/{articulo}', [InventoryController::class, 'show'])->middleware('auth')->name('inventory.show');
Route::get('/inventario/movimientos', [InventoryController::class, 'movements'])->middleware('auth')->name('inventory.movements');
Route::get('/inventario/ajustes/crear', [InventoryController::class, 'adjustmentCreate'])->middleware('auth')->name('inventory.adjustments.create');
Route::post('/inventario/ajustes', [InventoryController::class, 'adjustmentStore'])->middleware('auth')->name('inventory.adjustments.store');
Route::get('/inventario/conteos', [InventoryController::class, 'counts'])->middleware('auth')->name('inventory.counts');
Route::get('/inventario/conteos/crear', [InventoryController::class, 'countCreate'])->middleware('auth')->name('inventory.counts.create');
Route::post('/inventario/conteos', [InventoryController::class, 'countStore'])->middleware('auth')->name('inventory.counts.store');
Route::post('/inventario/conteos/{conteo}/confirmar', [InventoryController::class, 'countConfirm'])->middleware('auth')->name('inventory.counts.confirm');
Route::get('/inventario/mermas', [InventoryController::class, 'wastes'])->middleware('auth')->name('inventory.wastes');
Route::get('/inventario/mermas/crear', [InventoryController::class, 'wasteCreate'])->middleware('auth')->name('inventory.wastes.create');
Route::post('/inventario/mermas', [InventoryController::class, 'wasteStore'])->middleware('auth')->name('inventory.wastes.store');

Route::get('/recepciones', [ReceiptController::class, 'index'])->middleware('auth')->name('receipts.index');
Route::get('/recepciones/crear', [ReceiptController::class, 'create'])->middleware('auth')->name('receipts.create');
Route::post('/recepciones', [ReceiptController::class, 'store'])->middleware('auth')->name('receipts.store');
Route::get('/recepciones/{recepcion}', [ReceiptController::class, 'show'])->middleware('auth')->name('receipts.show');
Route::post('/recepciones/{recepcion}/lineas', [ReceiptController::class, 'addLine'])->middleware('auth')->name('receipts.lines.store');
Route::delete('/recepciones/{recepcion}/lineas/{detalle}', [ReceiptController::class, 'removeLine'])->middleware('auth')->name('receipts.lines.destroy');
Route::post('/recepciones/{recepcion}/confirmar', [ReceiptController::class, 'confirm'])->middleware('auth')->name('receipts.confirm');
Route::post('/recepciones/{recepcion}/anular', [ReceiptController::class, 'annul'])->middleware('auth')->name('receipts.annul');

Route::get('/recetas', [RecipeController::class, 'index'])->middleware('auth')->name('recipes.index');
Route::get('/recetas/crear', [RecipeController::class, 'create'])->middleware('auth')->name('recipes.create');
Route::post('/recetas', [RecipeController::class, 'store'])->middleware('auth')->name('recipes.store');
Route::get('/recetas/{receta}', [RecipeController::class, 'show'])->middleware('auth')->name('recipes.show');
Route::get('/recetas/{receta}/versiones/crear', [RecipeController::class, 'createVersion'])->middleware('auth')->name('recipes.versions.create');
Route::post('/recetas/{receta}/versiones', [RecipeController::class, 'storeVersion'])->middleware('auth')->name('recipes.versions.store');
Route::post('/recetas/{receta}/activar', [RecipeController::class, 'activate'])->middleware('auth')->name('recipes.activate');

Route::get('/producciones', [ProductionController::class, 'index'])->middleware('auth')->name('productions.index');
Route::get('/producciones/crear', [ProductionController::class, 'create'])->middleware('auth')->name('productions.create');
Route::post('/producciones', [ProductionController::class, 'store'])->middleware('auth')->name('productions.store');
Route::get('/producciones/{produccion}', [ProductionController::class, 'show'])->middleware('auth')->name('productions.show');
Route::post('/producciones/{produccion}/confirmar', [ProductionController::class, 'confirm'])->middleware('auth')->name('productions.confirm');
Route::post('/producciones/{produccion}/anular', [ProductionController::class, 'annul'])->middleware('auth')->name('productions.annul');

Route::get('/personal', [PersonnelController::class, 'index'])->middleware('auth')->name('personnel.index');
Route::get('/personal/crear', [PersonnelController::class, 'create'])->middleware('auth')->name('personnel.create');
Route::post('/personal', [PersonnelController::class, 'store'])->middleware('auth')->name('personnel.store');
Route::get('/personal/reporte-pago', [PersonnelController::class, 'report'])->middleware('auth')->name('personnel.pay-report');
Route::get('/personal/{trabajador}/editar', [PersonnelController::class, 'edit'])->middleware('auth')->name('personnel.edit');
Route::put('/personal/{trabajador}', [PersonnelController::class, 'update'])->middleware('auth')->name('personnel.update');
Route::post('/personal/{trabajador}/activar', [PersonnelController::class, 'activate'])->middleware('auth')->name('personnel.activate');
Route::post('/personal/{trabajador}/desactivar', [PersonnelController::class, 'deactivate'])->middleware('auth')->name('personnel.deactivate');
Route::post('/personal/{trabajador}/tarifas', [PersonnelController::class, 'storeRate'])->middleware('auth')->name('personnel.rates.store');
Route::get('/personal/{trabajador}', [PersonnelController::class, 'show'])->middleware('auth')->name('personnel.show');

Route::get('/asistencia', [PersonnelController::class, 'attendance'])->middleware('auth')->name('attendance.mark');
Route::post('/asistencia/iniciar', [PersonnelController::class, 'start'])->middleware('auth')->name('attendance.start');
Route::post('/asistencia/{jornada}/cerrar', [PersonnelController::class, 'close'])->middleware('auth')->name('attendance.close');
Route::get('/asistencia/gestionar', [PersonnelController::class, 'managedAttendance'])->middleware('auth')->name('attendance.index');
Route::post('/asistencia/gestionar/manual', [PersonnelController::class, 'storeManual'])->middleware('auth')->name('attendance.manual.store');
Route::post('/asistencia/gestionar/{jornada}/anular', [PersonnelController::class, 'annul'])->middleware('auth')->name('attendance.annul');

Route::get('/ventas/nueva', [SaleController::class, 'create'])
    ->middleware('auth')
    ->name('sales.create');

Route::get('/ventas/buscar', [SaleController::class, 'search'])
    ->middleware('auth')
    ->name('sales.search');

Route::post('/ventas', [SaleController::class, 'store'])
    ->middleware('auth')
    ->name('sales.store');

Route::get('/ventas/resultado', [SaleController::class, 'success'])
    ->middleware('auth')
    ->name('sales.success');

Route::get('/pedidos', [OrderController::class, 'index'])
    ->middleware('auth')
    ->name('orders.index');

Route::get('/pedidos/crear', [OrderController::class, 'create'])
    ->middleware('auth')
    ->name('orders.create');

Route::get('/pedidos/articulos/buscar', [OrderController::class, 'searchArticles'])
    ->middleware('auth')
    ->name('orders.articles.search');

Route::get('/pedidos/clientes/buscar', [OrderController::class, 'searchClients'])
    ->middleware('auth')
    ->name('orders.clients.search');

Route::post('/pedidos', [OrderController::class, 'store'])
    ->middleware('auth')
    ->name('orders.store');

Route::get('/pedidos/{pedido}', [OrderController::class, 'show'])
    ->middleware('auth')
    ->name('orders.show');

Route::post('/pedidos/{pedido}/confirmar', [OrderController::class, 'confirm'])
    ->middleware('auth')
    ->name('orders.confirm');

Route::post('/pedidos/{pedido}/preparacion', [OrderController::class, 'preparation'])
    ->middleware('auth')
    ->name('orders.preparation');

Route::post('/pedidos/{pedido}/listo', [OrderController::class, 'ready'])
    ->middleware('auth')
    ->name('orders.ready');

Route::post('/pedidos/{pedido}/cancelar', [OrderController::class, 'cancel'])
    ->middleware('auth')
    ->name('orders.cancel');

Route::post('/pedidos/{pedido}/entregar', [OrderController::class, 'deliver'])
    ->middleware('auth')
    ->name('orders.deliver');

Route::get('/caja', [CashController::class, 'index'])
    ->middleware('auth')
    ->name('cash.index');

Route::get('/caja/movimientos', [CashController::class, 'movements'])
    ->middleware('auth')
    ->name('cash.movements');

Route::post('/caja/sesiones', [CashController::class, 'storeSession'])
    ->middleware('auth')
    ->name('cash.sessions.store');

Route::post('/caja/sesiones/{sesion}/cerrar', [CashController::class, 'closeSession'])
    ->middleware('auth')
    ->name('cash.sessions.close');

Route::post('/caja/sesiones/{sesion}/movimientos', [CashController::class, 'storeMovement'])
    ->middleware('auth')
    ->name('cash.movements.store');

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::prefix('administracion')->middleware('auth')->group(function (): void {
    Route::get('/usuarios', [AdministrationController::class, 'users'])->name('admin.users.index');
    Route::get('/usuarios/crear', [AdministrationController::class, 'createUser'])->name('admin.users.create');
    Route::post('/usuarios', [AdministrationController::class, 'storeUser'])->name('admin.users.store');
    Route::get('/usuarios/{usuario}', [AdministrationController::class, 'showUser'])->name('admin.users.show');
    Route::get('/usuarios/{usuario}/editar', [AdministrationController::class, 'editUser'])->name('admin.users.edit');
    Route::put('/usuarios/{usuario}', [AdministrationController::class, 'updateUser'])->name('admin.users.update');
    Route::post('/usuarios/{usuario}/activar', [AdministrationController::class, 'activateUser'])->name('admin.users.activate');
    Route::post('/usuarios/{usuario}/desactivar', [AdministrationController::class, 'deactivateUser'])->name('admin.users.deactivate');

    Route::get('/roles', [AdministrationController::class, 'roles'])->name('admin.roles.index');
    Route::get('/roles/crear', [AdministrationController::class, 'createRole'])->name('admin.roles.create');
    Route::post('/roles', [AdministrationController::class, 'storeRole'])->name('admin.roles.store');
    Route::get('/roles/{rol}', [AdministrationController::class, 'showRole'])->name('admin.roles.show');
    Route::get('/roles/{rol}/editar', [AdministrationController::class, 'editRole'])->name('admin.roles.edit');
    Route::put('/roles/{rol}', [AdministrationController::class, 'updateRole'])->name('admin.roles.update');
    Route::post('/roles/{rol}/activar', [AdministrationController::class, 'activateRole'])->name('admin.roles.activate');
    Route::post('/roles/{rol}/desactivar', [AdministrationController::class, 'deactivateRole'])->name('admin.roles.deactivate');
    Route::put('/roles/{rol}/permisos', [AdministrationController::class, 'syncRolePermissions'])->name('admin.roles.permissions.update');

    Route::get('/permisos', [AdministrationController::class, 'permissions'])->name('admin.permissions.index');
});
