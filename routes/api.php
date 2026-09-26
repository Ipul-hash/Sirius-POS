<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CashShiftController;
use App\Http\Controllers\Api\Kds\KdsController;
use App\Http\Controllers\Api\Pos\PosCartController;
use App\Http\Controllers\Api\Pos\PosCheckoutController;
use App\Http\Controllers\Api\Pos\PosProductController;
use Illuminate\Support\Facades\Route;

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
});

Route::prefix('kds')->group(function () {
    Route::get('/tickets', [KdsController::class, 'index'])->name('kds.tickets.index');
    Route::get('/tickets/{id}', [KdsController::class, 'show'])->name('kds.tickets.show');
    Route::patch('/tickets/{id}/status', [KdsController::class, 'updateStatus'])->name('kds.tickets.update-status');
    Route::put('/tickets/{id}/status', [KdsController::class, 'updateStatus']);
});
