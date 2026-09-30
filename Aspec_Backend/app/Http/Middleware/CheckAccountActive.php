<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAccountActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilizador não autenticado.',
            ], 401);
        }

        if ($user->accountStatus?->name === 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'A conta está pendente e não pode editar dados.',
            ], 403);
        }

        return $next($request);
    }
}
