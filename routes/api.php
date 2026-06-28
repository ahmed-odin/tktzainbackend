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
        Route::get('/filter-users', [TicketController::class, 'filterUsers']);
        Route::post('/', [TicketController::class, 'store']);
        Route::post('/bulk', [TicketController::class, 'bulkStore']);
        Route::put('/{id}', [TicketController::class, 'update']);
        Route::delete('/{id}', [TicketController::class, 'destroy']);
        Route::post('/{id}/complete', [TicketController::class, 'markComplete']);
        Route::post('/{id}/reply', [TicketController::class, 'reply']);
    });
});
