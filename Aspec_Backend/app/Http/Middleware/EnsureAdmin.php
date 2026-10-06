<?php

namespace App\Http\Middleware;

use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    use ApiResponse;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $this->errorResponse(
                'Não autenticado.',
                Response::HTTP_UNAUTHORIZED
            );
        }

        if ($user->role?->name !== 'Admin') {
            return $this->errorResponse(
                'Não tem permissão para realizar esta ação.',
                Response::HTTP_FORBIDDEN
            );
        }

        return $next($request);
    }
}