<?php

use App\Http\Controllers\Api\MobileAuthController;
use App\Http\Controllers\Api\MobileProductController;
use App\Http\Controllers\Api\MobileSaleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API Routes (Cashier App)
|--------------------------------------------------------------------------
*/
Route::prefix('mobile')->group(function () {
    // ── Public ──
    Route::get('/health', fn () => response()->noContent())
        ->withoutMiddleware('throttle:api');

    Route::post('/login', [MobileAuthController::class, 'login']);

    // ── Protected (butuh Bearer Token) ──
    Route::middleware('auth:sanctum')->group(function () {
        // ══ Auth / Session ══
        Route::get('/me',      [MobileAuthController::class, 'me']);
        Route::post('/logout', [MobileAuthController::class, 'logout']);

        // ══ Update Profil ══
        Route::put('/me',              [MobileAuthController::class, 'updateProfile']);
        Route::post('/me/avatar',      [MobileAuthController::class, 'updateAvatar']);
        Route::put('/me/password',     [MobileAuthController::class, 'updatePassword'])
            ->middleware('throttle:password-change');

        // ══ Update Toko ══
        Route::put('/store',           [MobileAuthController::class, 'updateStore']);
        Route::post('/store/avatar',   [MobileAuthController::class, 'updateStoreAvatar']);

        // ══ Produk ══
        Route::get('/products', [MobileProductController::class, 'index']);

        // ══ Sales ══
        Route::get('/sales',                     [MobileSaleController::class, 'index']);
        Route::get('/sales/product-summary',     [MobileSaleController::class, 'productSummary']);
        Route::post('/sales',                    [MobileSaleController::class, 'store']);
        Route::get('/sales/{sale}',              [MobileSaleController::class, 'show']);
        Route::put('/sales/{sale}',              [MobileSaleController::class, 'update']);
        Route::delete('/sales/{sale}',           [MobileSaleController::class, 'destroy']);
    });
});
