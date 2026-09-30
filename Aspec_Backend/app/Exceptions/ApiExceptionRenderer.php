<?php

namespace App\Exceptions;

use App\Traits\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Converte as exceções dos pedidos `api/*` em respostas JSON no formato do ApiResponse.
 * É usado pelos callbacks de `withExceptions` em `bootstrap/app.php`.
 */
class ApiExceptionRenderer
{
    use ApiResponse;

    /**
     * Mensagens genéricas por código HTTP.
     */
    private const GENERIC_MESSAGES = [
        Response::HTTP_UNAUTHORIZED => 'Não autenticado.',
        Response::HTTP_FORBIDDEN => 'Não tem permissão para realizar esta ação.',
        Response::HTTP_NOT_FOUND => 'Recurso não encontrado.',
        Response::HTTP_METHOD_NOT_ALLOWED => 'Método não permitido.',
        Response::HTTP_UNPROCESSABLE_ENTITY => 'Os dados enviados são inválidos.',
        Response::HTTP_TOO_MANY_REQUESTS => 'Demasiados pedidos. Tente novamente mais tarde.',
        Response::HTTP_INTERNAL_SERVER_ERROR => 'Ocorreu um erro interno. Tente novamente mais tarde.',
    ];

    /**
     * Mensagem usada para códigos HTTP sem mensagem genérica própria (ex. 409 sem mensagem).
     */
    private const FALLBACK_MESSAGE = 'Ocorreu um erro ao processar o pedido.';

    /**
     * 422: mensagem genérica e os erros por campo em `errors`, ao nível de topo.
     */
    public function validation(ValidationException $e): JsonResponse
    {
        return $this->errorResponse(
            self::GENERIC_MESSAGES[Response::HTTP_UNPROCESSABLE_ENTITY],
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $e->errors()
        );
    }

    /**
     * 401: sempre JSON, sem redirecionar para uma rota de login.
     */
    public function unauthenticated(AuthenticationException $e): JsonResponse
    {
        return $this->errorResponse(
            self::GENERIC_MESSAGES[Response::HTTP_UNAUTHORIZED],
            Response::HTTP_UNAUTHORIZED
        );
    }

    /**
     * Responde com a mensagem genérica do código da exceção (usado no 405 e no 429,
     * cujas mensagens originais são internas e em inglês).
     */
    public function withGenericMessage(HttpExceptionInterface $e): JsonResponse
    {
        $code = $e->getStatusCode();

        return $this->errorResponse($this->genericMessage($code), $code)
            ->withHeaders($e->getHeaders());
    }

    /**
     * Restantes HttpException (403, 404, 409, ...). Mantém a mensagem só quando foi escrita
     * pela equipa num `abort()`; nos outros casos usa a genérica do código.
     */
    public function httpException(HttpExceptionInterface $e, Request $request): JsonResponse
    {
        $code = $e->getStatusCode();

        return $this->errorResponse($this->httpMessage($e, $request), $code)
            ->withHeaders($e->getHeaders());
    }

    /**
     * 500 genérico sem detalhes internos. Devolve null com APP_DEBUG=true (ou quando a exceção
     * já traz a sua resposta) para o Laravel tratar da forma habitual.
     */
    public function serverError(Throwable $e): ?JsonResponse
    {
        if (config('app.debug') || $e instanceof HttpResponseException) {
            return null;
        }

        return $this->errorResponse(
            self::GENERIC_MESSAGES[Response::HTTP_INTERNAL_SERVER_ERROR],
            Response::HTTP_INTERNAL_SERVER_ERROR
        );
    }

    /**
     * Escolhe a mensagem de uma HttpException segundo a regra da decisão 19: só preserva
     * mensagens de um abort() da equipa; nos restantes casos usa a genérica do código.
     */
    private function httpMessage(HttpExceptionInterface $e, Request $request): string
    {
        // abort() lança exatamente HttpException ou NotFoundHttpException; as subclasses
        // (ex. InvalidSignatureException) vêm do framework, com mensagens internas em inglês.
        $fromAbort = in_array($e::class, [HttpException::class, NotFoundHttpException::class], true);

        // getPrevious() preenchido = exceção interna convertida pelo Laravel (ModelNotFoundException,
        // AuthorizationException, ...): a mensagem original é inglesa e pode expor o nome do model.
        // Sem rota resolvida = rota inexistente, cuja mensagem inclui o caminho em inglês.
        if (! $fromAbort || $e->getPrevious() !== null || $request->route() === null || $e->getMessage() === '') {
            return $this->genericMessage($e->getStatusCode());
        }

        return $e->getMessage();
    }

    /**
     * Mensagem genérica para um código HTTP.
     */
    private function genericMessage(int $code): string
    {
        return self::GENERIC_MESSAGES[$code] ?? self::FALLBACK_MESSAGE;
    }
}
