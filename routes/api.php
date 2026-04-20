<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HotelController;
use App\Http\Controllers\ChatController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ==================== ROUTES PUBLIQUES ====================

// Health check
Route::get('/health', function() {
    return response()->json([
        'status' => 'ok',
        'service' => 'Backend Red Product API',
        'timestamp' => now()
    ]);
});

// Test simple
Route::get('/ping', function() {
    return response()->json(['message' => 'pong']);
});

// Authentification
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Images (public)
Route::get('/storage/{path}', function ($path) {
    $fullPath = storage_path('app/public/' . $path);
    if (!file_exists($fullPath)) {
        return response()->json(['message' => 'Image not found'], 404);
    }
    return response()->file($fullPath);
})->where('path', '.*');

// ==================== ROUTES PROTÉGÉES ====================

Route::middleware('auth:sanctum')->group(function () {
    
    // Utilisateur
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/user', [AuthController::class, 'update']);
    Route::delete('/user', [AuthController::class, 'delete']);
    
    // Hôtels (CRUD complet)
    Route::apiResource('hotels', HotelController::class);
    
    // Chat
    Route::post('/chat', [ChatController::class, 'chat']);
});