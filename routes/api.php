<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CashShiftController;
use App\Http\Controllers\Api\Consignment\ConsignmentController;
use App\Http\Controllers\Api\Inventory\InventoryController;
use App\Http\Controllers\Api\Kds\KdsController;
use App\Http\Controllers\Api\Pos\PosCartController;
use App\Http\Controllers\Api\Pos\PosCheckoutController;
use App\Http\Controllers\Api\Pos\PosProductController;
use App\Http\Controllers\Api\Pos\PosSecurityController;
use App\Http\Controllers\Api\Purchasing\PurchasingController;
use App\Http\Controllers\Api\Report\ReportController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\BranchController;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/shift/current', [CashShiftController::class, 'current']);
    Route::post('/shift/open', [CashShiftController::class, 'open']);
    Route::post('/shift/petty-cash', [CashShiftController::class, 'pettyCash']);
    Route::post('/shift/close', [CashShiftController::class, 'close']);
});

Route::prefix('pos')->group(function () {
    Route::get('/products', [PosProductController::class, 'index']);
    Route::post('/calculate-cart', [PosCartController::class, 'calculate']);
    Route::post('/checkout', [PosCheckoutController::class, 'checkout']);
    Route::post('/void-order', [PosSecurityController::class, 'voidOrder'])->name('pos.void-order');
    Route::post('/open-drawer', [PosSecurityController::class, 'openDrawer'])->name('pos.open-drawer');
});

Route::prefix('kds')->group(function () {
    Route::get('/tickets', [KdsController::class, 'index'])->name('kds.tickets.index');
    Route::get('/tickets/{id}', [KdsController::class, 'show'])->name('kds.tickets.show');
    Route::patch('/tickets/{id}/status', [KdsController::class, 'updateStatus'])->name('kds.tickets.update-status');
    Route::put('/tickets/{id}/status', [KdsController::class, 'updateStatus']);
});

Route::prefix('inventory')->group(function () {
    Route::post('/transfers', [InventoryController::class, 'transfer'])->name('inventory.transfers');
    Route::get('/expiry-alerts', [InventoryController::class, 'expiryAlerts'])->name('inventory.expiry-alerts');
    Route::post('/waste', [InventoryController::class, 'recordWaste'])->name('inventory.waste');
    Route::post('/stock-opnames', [InventoryController::class, 'stockOpname'])->name('inventory.stock-opnames');
});

Route::prefix('purchasing')->group(function () {
    Route::post('/orders', [PurchasingController::class, 'createOrder'])->name('purchasing.orders.create');
    Route::post('/goods-receipt', [PurchasingController::class, 'goodsReceipt'])->name('purchasing.goods-receipt');
    Route::get('/ap-alerts', [PurchasingController::class, 'apAlerts'])->name('purchasing.ap-alerts');
});

Route::prefix('consignment')->group(function () {
    Route::post('/settlements', [ConsignmentController::class, 'settlement'])->name('consignment.settlements');
});

Route::prefix('reports')->group(function () {
    Route::get('/daily-pnl', [ReportController::class, 'dailyPnl'])->name('reports.daily-pnl');
});
