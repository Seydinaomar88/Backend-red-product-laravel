<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HotelController;
use App\Http\Controllers\ChatController;

/* PUBLIC ROUTES */
Route::get('/health', function() {
    return response()->json([
        'status' => 'ok',
        'service' => 'Backend Red Product API',
        'timestamp' => now()
    ]);
});

Route::get('/test-db', function () {
    return [
        'host' => config('database.connections.mysql.host'),
        'db' => config('database.connections.mysql.database'),
    ];
});

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

/* PROTECTED ROUTES */
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/user', [AuthController::class, 'update']);
    Route::delete('/user', [AuthController::class, 'delete']);
    
    Route::get('/hotels', [HotelController::class, 'index']);
    Route::post('/hotels', [HotelController::class, 'store']);
    Route::get('/hotels/{hotel}', [HotelController::class, 'show']);
    Route::put('/hotels/{hotel}', [HotelController::class, 'update']);
    Route::delete('/hotels/{hotel}', [HotelController::class, 'destroy']);
    
    Route::post('/chat', [ChatController::class, 'chat']);
});