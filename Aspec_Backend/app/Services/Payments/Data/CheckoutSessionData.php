<?php

namespace App\Services\Payments\Data;

use Stripe\Checkout\Session;

/**
 * Sessão do Stripe Checkout, só com o que a ativação precisa.
 * O status é o do Stripe: open, complete ou expired.
 */
final readonly class CheckoutSessionData
{
    public function __construct(
        public string $id,
        public ?string $url,
        public string $status,
    ) {}

    /**
     * Converte a sessão devolvida pelo SDK do Stripe.
     */
    public static function fromStripe(Session $session): self
    {
        return new self($session->id, $session->url, $session->status);
    }
}
