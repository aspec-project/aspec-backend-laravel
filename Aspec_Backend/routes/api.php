<?php

use App\Http\Controllers\Api\Member\MemberProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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

// TODO: acrescentar o middleware 'active' (CheckAccountActive, colega da auth) quando estiver em dev.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/member-profile', [MemberProfileController::class, 'show']);
});
