<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware(['api', AuthenticateApiToken::class])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::prefix('users')->group(function () {
        Route::post('/', [UserController::class, 'store']);
        Route::get('/', [UserController::class, 'index']);
        Route::put('/{id}', [UserController::class, 'update']);
        Route::delete('/{id}', [UserController::class, 'destroy']);
    });

    Route::prefix('tickets')->group(function () {
        Route::get('/pending', [TicketController::class, 'pending']);
        Route::get('/completed', [TicketController::class, 'completed']);
        Route::get('/governorates', [TicketController::class, 'governorates']);
        Route::post('/', [TicketController::class, 'store']);
        Route::put('/{id}', [TicketController::class, 'update']);
        Route::post('/{id}/complete', [TicketController::class, 'markComplete']);
    });
});
