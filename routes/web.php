<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/central/register', [AuthController::class, 'register'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('central.register');