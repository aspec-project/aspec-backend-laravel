<?php

use App\Http\Controllers\Api\Account\AccountPasswordController;
use App\Http\Controllers\Api\Member\MemberLogoController;
use App\Http\Controllers\Api\Member\MemberProfileController;
use App\Http\Controllers\Api\Member\PortfolioController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Auth\AuthController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');



Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});


Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    });



Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
    Route::get('/member-profile', [MemberProfileController::class, 'show']);
    Route::put('/member-profile', [MemberProfileController::class, 'update'])->middleware('throttle:10,1');
    Route::post('/member-profile/logo', [MemberLogoController::class, 'store'])->middleware('throttle:10,1');
    Route::post('/member-portfolio', [PortfolioController::class, 'store'])->middleware('throttle:10,1');
    Route::delete('/member-portfolio/{id}', [PortfolioController::class, 'destroy'])->middleware('throttle:10,1');
    Route::put('/account/password', [AccountPasswordController::class, 'update'])->middleware('throttle:10,1');
});

