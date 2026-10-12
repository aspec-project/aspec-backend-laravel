<?php

namespace App\Services\Payments;

use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;

class StripeCustomerService
{
    /**
     * Apaga o cliente no Stripe (um só pedido, sem o ir buscar antes).
     * Um cliente que já não existe conta como sucesso, para repetir a operação sem erro.
     *
     * @throws ApiErrorException Se o Stripe falhar por outro motivo (rede, chave, etc.).
     */
    public function delete(string $stripeId): void
    {
        try {
            Cashier::stripe()->customers->delete($stripeId);
        } catch (InvalidRequestException $e) {
            if ($e->getStripeCode() !== 'resource_missing') {
                throw $e;
            }
        }
    }
}
