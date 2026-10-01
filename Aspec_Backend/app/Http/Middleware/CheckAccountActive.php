<?php

namespace App\Http\Middleware;

use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAccountActive
{
    use ApiResponse;
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $this->errorResponse(
                'Utilizador não autenticado.',
                Response::HTTP_UNAUTHORIZED
            );
        }

        if ($user->accountStatus?->name === 'Pending') {
            return $this->errorResponse(
                'A conta está pendente e não pode editar dados.',
                Response::HTTP_FORBIDDEN
            );
        }

        return $next($request);
    }
}
