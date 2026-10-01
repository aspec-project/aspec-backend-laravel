<?php

use App\Exceptions\ApiExceptionRenderer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\CheckAccountActive;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
        'account.active' => CheckAccountActive::class,
    ]);
        //
        // Sem isto, um 401 em api/* sem header Accept tenta gerar route('login'), que não existe (500).
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : route('login')
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Pedidos api/* respondem sempre em JSON, mesmo sem o header Accept.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );

        // Os callbacks são testados por ordem: os tipos mais específicos vêm antes dos genéricos.
        $exceptions->render(fn (ValidationException $e, Request $request) => $request->is('api/*')
            ? app(ApiExceptionRenderer::class)->validation($e)
            : null);

        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->is('api/*')
            ? app(ApiExceptionRenderer::class)->unauthenticated($e)
            : null);

        $exceptions->render(fn (MethodNotAllowedHttpException $e, Request $request) => $request->is('api/*')
            ? app(ApiExceptionRenderer::class)->withGenericMessage($e)
            : null);

        $exceptions->render(fn (ThrottleRequestsException $e, Request $request) => $request->is('api/*')
            ? app(ApiExceptionRenderer::class)->withGenericMessage($e)
            : null);

        $exceptions->render(fn (HttpExceptionInterface $e, Request $request) => $request->is('api/*')
            ? app(ApiExceptionRenderer::class)->httpException($e, $request)
            : null);

        $exceptions->render(fn (Throwable $e, Request $request) => $request->is('api/*')
            ? app(ApiExceptionRenderer::class)->serverError($e)
            : null);
    })->create();
