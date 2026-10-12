<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Limite de falhas do current_password (5 falhas em 15 minutos, por utilizador),
 * partilhado pelos Form Requests que validam a password atual.
 */
trait LimitsCurrentPasswordAttempts
{
    private const MAX_CURRENT_PASSWORD_FAILURES = 5;

    private const CURRENT_PASSWORD_DECAY_SECONDS = 900;

    /**
     * Antes da validação: se o pedido traz current_password e o utilizador está bloqueado,
     * responde 429 mesmo com a password certa (sem contar como nova falha).
     *
     * @throws ThrottleRequestsException
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('current_password')) {
            return;
        }

        $key = $this->currentPasswordThrottleKey();

        if (RateLimiter::tooManyAttempts($key, self::MAX_CURRENT_PASSWORD_FAILURES)) {
            throw new ThrottleRequestsException(headers: [
                'Retry-After' => RateLimiter::availableIn($key),
            ]);
        }
    }

    /**
     * Conta uma falha só quando a regra current_password falhou (password errada),
     * não quando o campo falta nem noutros erros de validação.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function failedValidation(Validator $validator): void
    {
        if (isset($validator->failed()['current_password']['CurrentPassword'])) {
            RateLimiter::hit($this->currentPasswordThrottleKey(), self::CURRENT_PASSWORD_DECAY_SECONDS);
        }

        parent::failedValidation($validator);
    }

    /**
     * Validação passou com a password atual enviada (preenchida) e correta: limpa o contador de falhas.
     */
    protected function passedValidation(): void
    {
        if ($this->filled('current_password')) {
            RateLimiter::clear($this->currentPasswordThrottleKey());
        }
    }

    /**
     * Mesma chave em todos os endpoints, para as falhas somarem entre eles.
     */
    private function currentPasswordThrottleKey(): string
    {
        return 'current-password:'.$this->user()->getKey();
    }
}
