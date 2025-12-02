<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\HoldController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentWebhookController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Public Routes
Route::get('/products/{id}', [ProductController::class, 'show']);
Route::post('/holds', [HoldController::class, 'store']);
Route::post('/orders', [OrderController::class, 'store']);
Route::post('/payments/webhook', [PaymentWebhookController::class, 'handle']);