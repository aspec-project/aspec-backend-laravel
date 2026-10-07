<?php

use App\Http\Controllers\Api\Account\AccountPasswordController;
use App\Http\Controllers\Api\Member\MemberLogoController;
use App\Http\Controllers\Api\Member\MemberProfileController;
use App\Http\Controllers\Api\Member\PortfolioController;
use App\Http\Controllers\Api\Lists\SectorController;
use App\Http\Controllers\Api\Lists\LocationController;
use App\Http\Controllers\Api\Lists\SocialplatformController;
use App\Http\Controllers\Api\Lists\WeekdayController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Admin\AdminUserController;


Route::post('/auth/token', [AuthController::class, 'token']);

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::get('/sectors', [SectorController::class, 'index']);
Route::get('/locations', [LocationController::class, 'index']);
Route::get('/social-platforms', [SocialPlatformController::class, 'index']);
Route::get('/week-days', [WeekDayController::class, 'index']);


Route::prefix('admin')
    ->middleware(['auth:sanctum','account.active', 'admin'])
    ->group(function () {
        Route::patch('/users/{id}/approve',[AdminUserController::class, 'approve']);
        Route::patch('/users/{id}/reject',[AdminUserController::class, 'reject']);
        Route::patch('/users/{id}/block',[AdminUserController::class, 'block']);
        Route::patch('/users/{id}/unblock',[AdminUserController::class, 'unblock']);
        // rotas administrativas
    });


Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});



Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
    Route::get('/member-profile', [MemberProfileController::class, 'show']);
    Route::put('/member-profile', [MemberProfileController::class, 'update'])->middleware('throttle:profile-update');
    Route::post('/member-profile/logo', [MemberLogoController::class, 'store'])->middleware('throttle:logo-upload');
    Route::post('/member-portfolio', [PortfolioController::class, 'store'])->middleware('throttle:portfolio-upload');
    Route::delete('/member-portfolio/{id}', [PortfolioController::class, 'destroy'])->middleware('throttle:portfolio-delete');
    Route::put('/account/password', [AccountPasswordController::class, 'update'])->middleware('throttle:password-update');
});

