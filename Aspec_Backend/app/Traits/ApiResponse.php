<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * Resposta de sucesso no formato {success, message, data}.
     *
     * @return JsonResponse
     */
    public function successResponse($data, ?string $message = null, int $code = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $code);
    }

    /**
     * Resposta de erro no formato {success, message}.
     * A chave `errors` (erros por campo, usada nos 422) só é incluída quando não é null.
     *
     * @param  array<string, array<int, string>>|null  $errors
     * @return JsonResponse
     */
    public function errorResponse(string $message, int $code, ?array $errors = null)
    {
        $body = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $code);
    }
}
