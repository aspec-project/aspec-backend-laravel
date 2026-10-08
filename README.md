# aspec-backend-laravel

## Setup local

Na pasta `Aspec_Backend/`:

1. Copiar `.env.example` para `.env`. O `APP_URL` já vem como `http://localhost:8000`, o endereço do `php artisan serve` e o que o frontend usa por omissão (`VITE_API_URL`). Se a API correr noutro endereço (ex. domínio do Laragon), mudar o `APP_URL` e o `VITE_API_URL` do frontend para o mesmo valor. Os URLs das imagens (logótipos e portfólio) são gerados a partir do `APP_URL`, com a porta incluída; se estiver errado, as imagens não abrem no frontend.
2. `php artisan key:generate`
3. `php artisan migrate:fresh --seed` — cria as tabelas e os dados de referência (roles, estados de conta, setores, distritos, dias da semana, redes sociais). **Apaga todos os dados da base de dados local.**
4. `php artisan storage:link` — cria o atalho `public/storage` → `storage/app/public`. Sem ele, os ficheiros enviados (`logos/{user_id}`, `portfolios/{user_id}`) não ficam acessíveis por URL. O atalho é local a cada máquina e não vai para o git (`public/storage` está no `.gitignore`), por isso **cada pessoa tem de o criar uma vez**.
5. `php artisan serve` — a API fica em `http://localhost:8000/api`.

### Já tinha o projeto instalado?

Se o teu `.env` tem `APP_URL=http://localhost` (sem a porta), muda para `http://localhost:8000`, corre `php artisan config:clear` e confirma que existe `public/storage` (senão, `php artisan storage:link`). Sem isto, os logótipos e as imagens do portfólio aparecem partidos no frontend.

## Pagamentos (Stripe, modo de teste)

Os pagamentos usam o Laravel Cashier com o Stripe em **modo de teste**. Sem chaves no `.env` a aplicação funciona; só a anonimização de quem já tem cliente Stripe precisa delas. Os testes automáticos nunca usam o `.env` nem chamam o Stripe.

- **Chaves:** criar uma conta Stripe e, em **modo de teste**, copiar a `pk_test_…` para `STRIPE_KEY` e a `sk_test_…` para `STRIPE_SECRET` no `.env`. Nunca usar chaves `live` e nunca commitar o `.env`. Depois de mudar o `.env`: `php artisan config:clear` (não usar `config:cache` em desenvolvimento).
- **Preço:** no dashboard, criar o Product "Quota mensal ASPEC" com um Price mensal de 60 € e copiar o id (`price_…`) para `STRIPE_PRICE_ID`. O valor mostrado ao membro (`SUBSCRIPTION_PRICE_AMOUNT`) tem de bater certo com esse Price.
- **Webhooks locais** (a partir da ASPEC-129, quando existir a rota): instalar a Stripe CLI, `stripe login` e depois
  ```
  stripe listen --forward-to localhost:8000/api/stripe/webhook --events customer.subscription.created,customer.subscription.updated,customer.subscription.deleted,customer.deleted,invoice.payment_succeeded,invoice.payment_failed
  ```
  Copiar o `whsec_…` mostrado para `STRIPE_WEBHOOK_SECRET`. Mantém-se enquanto o login da CLI for válido; se mudar, atualizar o `.env` e `php artisan config:clear` (senão os webhooks locais dão 403). Para gerar eventos: `stripe trigger invoice.payment_failed`.
- **Pagamentos falhados (dashboard do Stripe → Billing → definições de pagamentos falhados):** depois das tentativas automáticas (Smart Retries, ≥ 7 dias), escolher **cancelar a subscrição**. Assim o Stripe deixa de cobrar e o webhook `customer.subscription.deleted` atualiza a subscrição local.
- **Cartões de teste:** `4242 4242 4242 4242` (sucesso), `4000 0000 0000 0341` (é aceite mas a cobrança falha → período de carência); qualquer data futura e qualquer CVC.
- **Test clocks** (dashboard do Stripe) para avançar o trial e a carência numa demonstração.
- **Processos em segundo plano:** `php artisan queue:work` (faturas, apagar o cliente Stripe, emails) e `php artisan schedule:work` (fim da carência).
- **Depois de `git pull`:** `composer install` e `php artisan migrate`.
