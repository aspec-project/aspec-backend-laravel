<?php

use App\Http\Controllers\Api\Account\AccountPasswordController;
use App\Http\Controllers\Api\Directory\MemberController;
use App\Http\Controllers\Api\Member\MemberLogoController;
use App\Http\Controllers\Api\Member\MemberProfileController;
use App\Http\Controllers\Api\Member\PortfolioController;
use App\Http\Controllers\Api\Lists\SectorController;
use App\Http\Controllers\Api\Lists\LocationController;
use App\Http\Controllers\Api\Lists\SocialPlatformController;
use App\Http\Controllers\Api\Lists\WeekDayController;
use App\Http\Controllers\Api\Lists\EventCategoryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AdminEventCategoryController;
use App\Http\Controllers\Api\Payments\AccountActivationController;
use App\Http\Controllers\Api\Payments\BillingPortalSessionController;
use App\Http\Controllers\Api\Payments\InvoiceController;
use App\Http\Controllers\Api\Payments\InvoicePdfController;
use App\Http\Controllers\Api\Payments\StripeWebhookController;
use App\Http\Controllers\Api\Payments\SubscriptionController;



Route::post('/auth/token', [AuthController::class, 'token'])->middleware('throttle:auth-token');

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware(['throttle:auth-forgot-password', 'throttle:auth-forgot-password-ip']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth-reset-password');
});

Route::get('/sectors', [SectorController::class, 'index']);
Route::get('/locations', [LocationController::class, 'index']);
Route::get('/social-platforms', [SocialPlatformController::class, 'index']);
Route::get('/week-days', [WeekDayController::class, 'index']);
Route::get('/event-categories', [EventCategoryController::class, 'index']);

// Públicas com utilizador opcional: o controller lê a sessão ou o Bearer para decidir se mostra os contactos.
Route::middleware('throttle:member-directory')->group(function () {
    Route::get('/members', [MemberController::class, 'index']);
});

Route::post('/account-activations/resend', [AccountActivationController::class, 'resend'])
    ->middleware('throttle:activation-resend')->name('account-activations.resend');

// Públicas: a autorização é a assinatura do link do email (relativa, porque o link aponta para o frontend).
Route::middleware('throttle:account-activation')->group(function () {
    Route::get('/account-activations/{user}', [AccountActivationController::class, 'show'])
        ->middleware('signed:relative')->whereUuid('user')->name('account-activations.show');
    Route::post('/account-activations/{user}', [AccountActivationController::class, 'store'])
        ->middleware('signed:relative')->whereUuid('user')->name('account-activations.store');
});

// Público e sem throttle: protegido pela assinatura do Stripe, que envia eventos em rajada.
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])->name('stripe.webhook');


Route::prefix('admin')
    ->middleware(['auth:sanctum','account.active', 'admin'])
    ->group(function () {
        Route::patch('/users/{id}/approve',[AdminUserController::class, 'approve']);
        Route::patch('/users/{id}/reject',[AdminUserController::class, 'reject']);
        Route::patch('/users/{id}/block',[AdminUserController::class, 'block']);
        Route::patch('/users/{id}/unblock',[AdminUserController::class, 'unblock']);
        Route::patch('/users/{id}/promote', [AdminUserController::class, 'promote']);
        Route::patch('/users/{id}/demote', [AdminUserController::class, 'demote']);
        Route::get('/users', [AdminUserController::class, 'index']);
        // rotas administrativas
        Route::get('/event-categories', [AdminEventCategoryController::class, 'index']);
        Route::post('/event-categories', [AdminEventCategoryController::class, 'store']);
        Route::put('/event-categories/{eventCategory}', [AdminEventCategoryController::class, 'update'])
            ->whereUuid('eventCategory');
        Route::delete('/event-categories/{eventCategory}', [AdminEventCategoryController::class, 'destroy'])
            ->whereUuid('eventCategory');
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
    Route::get('/subscription', [SubscriptionController::class, 'show']);
    Route::post('/subscription/billing-portal', [BillingPortalSessionController::class, 'store'])->middleware('throttle:billing-portal');
    Route::post('/subscription/cancel', [SubscriptionController::class, 'cancel'])->middleware('throttle:subscription-change');
    Route::post('/subscription/resume', [SubscriptionController::class, 'resume'])->middleware('throttle:subscription-change');
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/{invoice}/pdf', [InvoicePdfController::class, 'show'])
        ->whereUuid('invoice')->middleware('throttle:invoice-download');
});

