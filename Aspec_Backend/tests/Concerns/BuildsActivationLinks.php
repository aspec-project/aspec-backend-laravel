<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Services\Payments\SubscriptionService;
use Illuminate\Support\Facades\URL;

/**
 * Links de ativação/reativação nos testes.
 *
 * O email aponta para o frontend ({FRONTEND_URL}/ativacao/{user}?expires&signature) e o frontend
 * chama a API com o mesmo caminho e a mesma query; aqui faz-se essa conversão.
 */
trait BuildsActivationLinks
{
    protected const FRONTEND_URL = 'http://localhost:5173';

    protected function useTestFrontendUrl(): void
    {
        config(['app.frontend_url' => self::FRONTEND_URL]);
    }

    protected function toApiPath(string $frontendUrl): string
    {
        return str_replace(self::FRONTEND_URL.'/ativacao/', '/api/account-activations/', $frontendUrl);
    }

    protected function activationPath(User $user): string
    {
        return $this->toApiPath(app(SubscriptionService::class)->activationUrl($user));
    }

    /**
     * Caminho assinado corretamente para um id qualquer (ex.: um UUID que não existe).
     */
    protected function signedPathFor(string $userId): string
    {
        return URL::temporarySignedRoute(
            'account-activations.show',
            now()->addDays(7),
            ['user' => $userId],
            absolute: false,
        );
    }

    /**
     * @return array{expires: string, signature: string}
     */
    protected function signatureQuery(string $path): array
    {
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);

        return $query;
    }
}
