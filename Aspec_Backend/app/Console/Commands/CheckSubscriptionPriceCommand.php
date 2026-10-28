<?php

namespace App\Console\Commands;

use App\Services\Payments\StripeSubscriptionService;
use Illuminate\Console\Command;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;

/**
 * Verifica se o Price do Stripe (quem cobra) bate certo com a configuração usada nos emails, na
 * página da subscrição e nas faturas. Corrido à mão depois de mudar o Price ou o .env.
 */
class CheckSubscriptionPriceCommand extends Command
{
    protected $signature = 'subscriptions:check-price';

    protected $description = 'Compara o Price do Stripe (STRIPE_PRICE_ID) com o valor, a moeda e a periodicidade configurados.';

    /**
     * Lê o Price e compara active, currency, unit_amount (em cêntimos), recurring.interval e
     * recurring.interval_count (tem de ser 1). Imprime cada diferença com a variável a corrigir.
     * Sai com FAILURE se houver diferenças, se o STRIPE_PRICE_ID estiver vazio (sem chamar o
     * Stripe) ou se o Price não puder ser lido; a mensagem do Stripe (em inglês) não é mostrada.
     */
    public function handle(StripeSubscriptionService $stripe): int
    {
        $priceId = (string) config('subscription.price_id');

        if ($priceId === '') {
            $this->error('STRIPE_PRICE_ID não está configurado no .env.');

            return self::FAILURE;
        }

        try {
            $price = $stripe->retrievePrice($priceId);
        } catch (ApiErrorException) {
            $this->error("Não foi possível ler o Price {$priceId} no Stripe. Confirme o STRIPE_PRICE_ID e se as chaves são da mesma conta e modo (teste/live).");

            return self::FAILURE;
        }

        $differences = $this->differences($price);

        if ($differences === []) {
            $this->info("O Price {$priceId} bate certo com a configuração.");

            return self::SUCCESS;
        }

        $this->error("O Price {$priceId} não bate certo com a configuração:");

        foreach ($differences as [$message, $stripeValue, $configValue]) {
            $this->line(" - {$message}");

            if ($stripeValue !== null) {
                $this->line("     Stripe: {$stripeValue}");
                $this->line("     .env:   {$configValue}");
            }
        }

        $this->line('Depois de corrigir o .env: php artisan config:clear e volte a correr este comando.');

        return self::FAILURE;
    }

    /**
     * Diferenças entre o Price e a configuração, em português: mensagem com a variável a corrigir
     * e, quando se comparam valores, o do Stripe e o do .env (em linhas separadas).
     *
     * @return array<int, array{0: string, 1: ?string, 2: ?string}>
     */
    private function differences(Price $price): array
    {
        $differences = [];

        $currency = strtolower((string) config('subscription.currency'));
        $amount = (int) round((float) config('subscription.price_amount') * 100);
        $interval = (string) config('subscription.interval');

        if (! $price->active) {
            $differences[] = ['O Price está inativo no Stripe: ative-o ou crie outro e atualize STRIPE_PRICE_ID.', null, null];
        }

        if (strtolower((string) $price->currency) !== $currency) {
            $differences[] = ['Moeda diferente: corrija CASHIER_CURRENCY.', (string) $price->currency, $currency];
        }

        if ($price->unit_amount !== $amount) {
            $differences[] = ['Valor diferente: corrija SUBSCRIPTION_PRICE_AMOUNT.', $this->euros($price->unit_amount), $this->euros($amount)];
        }

        $recurring = $price->recurring;

        if ($recurring === null) {
            $differences[] = ['O Price não é recorrente: crie um Price recorrente e atualize STRIPE_PRICE_ID.', null, null];

            return $differences;
        }

        if ($recurring->interval !== $interval) {
            $differences[] = ['Periodicidade diferente: corrija SUBSCRIPTION_INTERVAL.', (string) $recurring->interval, $interval];
        }

        if ((int) $recurring->interval_count !== 1) {
            $differences[] = ["O Price cobra a cada {$recurring->interval_count} períodos (interval_count {$recurring->interval_count}): a quota tem de usar interval_count 1; crie outro Price e atualize STRIPE_PRICE_ID.", null, null];
        }

        return $differences;
    }

    /**
     * Valor em cêntimos no formato português (ex. "20,00 €").
     */
    private function euros(?int $cents): string
    {
        return $cents === null ? 'sem valor fixo' : number_format($cents / 100, 2, ',', '').' €';
    }
}
