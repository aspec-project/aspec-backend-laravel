# aspec-backend-laravel

## Setup local

Na pasta `Aspec_Backend/`:

1. Copiar `.env.example` para `.env`. O `APP_URL` já vem como `http://localhost:8000`, o endereço do `php artisan serve` e o que o frontend usa por omissão (`VITE_API_URL`). Se a API correr noutro endereço (ex. domínio do Laragon), mudar o `APP_URL` e o `VITE_API_URL` do frontend para o mesmo valor. Os URLs das imagens (logótipos e portfólio) são gerados a partir do `APP_URL`, com a porta incluída; se estiver errado, as imagens não abrem no frontend.
2. `php artisan key:generate`
3. `php artisan migrate:fresh --seed` — cria as tabelas e os dados de referência (roles, estados de conta, setores, distritos, dias da semana, redes sociais). **Apaga todos os dados da base de dados local.**
4. `php artisan storage:link` — cria o atalho `public/storage` → `storage/app/public`. Sem ele, os ficheiros enviados (`logos/{user_id}`, `portfolios/{user_id}`) não ficam acessíveis por URL. O atalho é local a cada máquina e não vai para o git (`public/storage` está no `.gitignore`), por isso **cada pessoa tem de o criar uma vez**.
5. `php artisan serve` — a API fica em `http://localhost:8000/api`.

No Postman usar **Obter token (Postman)**; o **Login** devolve 400 sem sessão de browser.

### Já tinha o projeto instalado?

Se o teu `.env` tem `APP_URL=http://localhost` (sem a porta), muda para `http://localhost:8000`, corre `php artisan config:clear` e confirma que existe `public/storage` (senão, `php artisan storage:link`). Sem isto, os logótipos e as imagens do portfólio aparecem partidos no frontend.

## Pagamentos (Stripe, modo de teste)

Os pagamentos usam o Laravel Cashier com o Stripe em **modo de teste**. Sem chaves no `.env` a aplicação funciona; só a anonimização de quem já tem cliente Stripe precisa delas. Os testes automáticos nunca usam o `.env` nem chamam o Stripe.

- **Chaves:** criar uma conta Stripe e, em **modo de teste**, copiar a `pk_test_…` para `STRIPE_KEY` e a `sk_test_…` para `STRIPE_SECRET` no `.env`. Nunca usar chaves `live` e nunca commitar o `.env`. Depois de mudar o `.env`: `php artisan config:clear` (não usar `config:cache` em desenvolvimento).
- **Preço:** no dashboard, criar o Product "Quota mensal ASPEC" com um Price mensal de 60 € e copiar o id (`price_…`) para `STRIPE_PRICE_ID`. O valor mostrado ao membro (`SUBSCRIPTION_PRICE_AMOUNT`) tem de bater certo com esse Price.
- **Webhooks locais:** instalar a Stripe CLI, `stripe login` e depois
  ```
  stripe listen --forward-to localhost:8000/api/stripe/webhook --events customer.subscription.created,customer.subscription.updated,customer.subscription.deleted,customer.deleted,invoice.payment_succeeded,invoice.payment_failed
  ```
  Copiar o `whsec_…` mostrado para `STRIPE_WEBHOOK_SECRET`. Mantém-se enquanto o login da CLI for válido; se mudar, atualizar o `.env` e `php artisan config:clear` (senão os webhooks locais dão 403). Para gerar eventos: `stripe trigger invoice.payment_failed`.
- **`stripe trigger invoice.payment_succeeded`:** prova a assinatura e a idempotência (aparece uma linha em `processed_webhook_events`), mas o cliente é criado pela CLI e não é nosso, por isso não há fatura (só um aviso no log). É normal.
- **Ver uma fatura** (com `stripe listen` e `php artisan queue:work` a correr), em `php artisan tinker`:
  ```php
  $u = App\Models\User::factory()->withBilling()->create();
  $u->newSubscription('default', config('subscription.price_id'))->create('pm_card_visa');
  ```
  A linha em `invoices` passa a `issued` e o `storage/logs/laravel.log` tem "Fatura emitida (driver log)". Para limpar: `$u->subscription('default')->cancelNow(); $u->anonymizeAndDelete();` (a fatura fica: obrigação fiscal).
- **Fila:** com `QUEUE_CONNECTION=database`, sem `php artisan queue:work` as faturas ficam `pending`.
- **Pagamentos falhados (dashboard do Stripe → Billing → definições de pagamentos falhados):** depois das tentativas automáticas (Smart Retries, ≥ 7 dias), escolher **cancelar a subscrição**. Assim o Stripe deixa de cobrar e o webhook `customer.subscription.deleted` atualiza a subscrição local.
- **Cartões de teste:** `4242 4242 4242 4242` (sucesso), `4000 0000 0000 0341` (é aceite mas a cobrança falha → período de carência); qualquer data futura e qualquer CVC.
- **Test clocks** (dashboard do Stripe) para avançar o trial e a carência numa demonstração.
- **Processos em segundo plano:** `php artisan queue:work` (faturas, apagar o cliente Stripe, emails) e `php artisan schedule:work` (fim da carência).
- **Depois de `git pull`:** `composer install` e `php artisan migrate`.

### Ativação da conta (demo ponta a ponta)

1. Correr ao mesmo tempo: `php artisan serve`, `php artisan queue:work` (sem ele os emails não saem), o `stripe listen` acima e o frontend (`http://localhost:5173`).
2. `FRONTEND_URL` no `.env` (por omissão `http://localhost:5173`) define o domínio dos links enviados por email.
3. Registar um membro e aprová-lo no backoffice ou com `PATCH /api/admin/users/{id}/approve`. A conta fica **Approved** (sem acesso) e é enviado o email de ativação.
4. Com `MAIL_MAILER=log`, abrir o link do email no `storage/logs/laravel.log` (`{FRONTEND_URL}/ativacao/{id}?expires=…&signature=…`), preencher os dados de faturação e seguir para o Stripe Checkout.
5. Pagar com o cartão `4242 4242 4242 4242`. O webhook `customer.subscription.created` passa a conta a **Active** em segundos (com o fim do período experimental em `users.trial_ends_at`) e envia o email de boas-vindas; o login passa a funcionar.

O link vale 7 dias (`ACTIVATION_LINK_DAYS` / `REACTIVATION_LINK_DAYS`) e pode ser aberto várias vezes: repetir o pedido com um Checkout ainda aberto devolve o mesmo pagamento. Desbloquear uma conta também envia o link (ativação, se nunca subscreveu; reativação, sem novo período experimental, se já subscreveu).
