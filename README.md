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

## Formatação do código (Pint)

O código segue o estilo do Laravel, verificado pelo [Pint](https://laravel.com/docs/pint). Antes de cada commit, na pasta `Aspec_Backend/`:

```bash
php vendor/bin/pint --dirty   # formata só os ficheiros alterados
php vendor/bin/pint --test    # confirma que o projeto inteiro está formatado
```

## Primeiro administrador

Na instalação inicial, a partir de `Aspec_Backend`:

1. Execute `php artisan migrate --seed` para criar as tabelas e os dados iniciais.
2. Defina `ASPEC_ADMIN_EMAIL` e `ASPEC_ADMIN_PASSWORD` no `.env`.(caso precise elas se encontram no .env.example) A password deve cumprir as regras de password da aplicação.
3. Execute `php artisan aspec:create-admin`.
4. Remova `ASPEC_ADMIN_PASSWORD` do `.env`.

Se a configuração estiver em cache, gere-a novamente depois de definir as variáveis e antes de executar o comando. Depois de remover a password, gere o cache outra vez para não a manter no ficheiro de configuração em cache.

O comando cria uma conta **Admin Active**, sem perfil de membro. Pode ser executado novamente sem criar outro administrador com o mesmo email. Se o email já pertencer a uma conta que não seja um administrador ativo, o comando recusa a criação e não altera essa conta.

## Dados de demonstração

**Só para desenvolvimento e demonstração.** O `DemoSeeder` recusa correr em produção e não entra no `migrate --seed`.

Na pasta `Aspec_Backend/`:

1. `php artisan migrate:fresh --seed` (apaga a base de dados local e cria os dados de referência).
2. `php artisan db:seed --class=DemoSeeder`
3. `php artisan storage:link` (uma vez por máquina; sem ele os logótipos e o portefólio não abrem).

Cria 19 contas, 13 perfis no diretório (com logótipo, horário e redes sociais; 5 com portefólio) e 10 eventos futuros nas quatro categorias, com datas a partir do dia em que corre. Pode correr outra vez: repõe as contas, os perfis e os eventos (as datas avançam) sem duplicar nada. As imagens são geradas com a extensão GD do PHP; sem ela, os perfis ficam sem imagens. Não chama o Stripe nem o InvoiceExpress: as contas Active não têm subscrição.

Password de todas as contas: `Demo#2026`

| Email | Papel | Estado |
|---|---|---|
| `demo.admin@aspec.test` | Admin | Active (também aparece no diretório) |
| `demo.membro@aspec.test` | Member | Active (perfil completo com portefólio) |
| `demo.membro01@aspec.test` … `demo.membro11@aspec.test` | Member | Active |
| `demo.pendente01@aspec.test` … `demo.pendente03@aspec.test` | Member | Pending (para aprovar no backoffice) |
| `demo.aprovado@aspec.test` | Member | Approved (à espera da ativação) |
| `demo.inativo.pagamento@aspec.test` | Member | Inactive (`unpaid`) |
| `demo.bloqueado@aspec.test` | Member | Inactive (`blocked`) |

## Email

Por omissão (`MAIL_MAILER=log`) os emails não saem: ficam escritos no `storage/logs/laravel.log`, incluindo os links de ativação. É o modo para desenvolvimento e o que os testes automáticos usam.

Para enviar emails a sério (demonstração), a plataforma usa a conta Gmail do projeto, `geral.teste.aspec@gmail.com`, com uma **palavra-passe de aplicação** (16 letras, criada em myaccount.google.com/apppasswords com a verificação em dois passos ativa). A palavra-passe da conta Google não funciona por SMTP. Pede a palavra-passe de aplicação ao Mário; nunca a pôr no `.env.example`, no git nem em mensagens públicas.

1. No `.env`, trocar o bloco `MAIL_*` pelo bloco comentado do `.env.example` (sem os `#`) e preencher `MAIL_PASSWORD` com a palavra-passe de aplicação, sem espaços. O `MAIL_FROM_ADDRESS` tem de ser o endereço da conta.
2. `php artisan config:clear`
3. Testar: `php artisan tinker --execute="Mail::raw('Teste ASPEC', fn (\$m) => \$m->to('o-teu-email')->subject('Teste ASPEC'));"`
4. A maior parte dos emails da aplicação (ativação, carência, faturas) passa pela fila: é preciso ter `php artisan queue:work` a correr.

Erros comuns: `535 Username and Password not accepted` → palavra-passe de aplicação errada ou verificação em dois passos desligada; `Connection could not be established` → a porta 587 está bloqueada (firewall ou antivírus).

**Limite:** o Gmail aceita cerca de 500 destinatários por dia. Chega para desenvolvimento e para a demonstração; em produção (anúncios de eventos e agenda semanal para todos os membros) é preciso um serviço de envio (ex. Brevo, Mailgun, Amazon SES). Só muda o bloco `MAIL_*` do `.env`.

## Pagamentos (Stripe, modo de teste)

Os pagamentos usam o Laravel Cashier com o Stripe em **modo de teste**. Sem chaves no `.env` a aplicação funciona; só a anonimização de quem já tem cliente Stripe precisa delas. Os testes automáticos nunca usam o `.env` nem chamam o Stripe.

- **Chaves:** criar uma conta Stripe e, em **modo de teste**, copiar a `pk_test_…` para `STRIPE_KEY` e a `sk_test_…` para `STRIPE_SECRET` no `.env`. Nunca usar chaves `live` e nunca commitar o `.env`. Depois de mudar o `.env`: `php artisan config:clear` (não usar `config:cache` em desenvolvimento).
- **Preço:** no dashboard, criar o Product "Quota mensal ASPEC" com um Price mensal de 60 € e copiar o id (`price_…`) para `STRIPE_PRICE_ID`. O valor mostrado ao membro (`SUBSCRIPTION_PRICE_AMOUNT`) tem de bater certo com esse Price.
- **Webhooks locais:** instalar a Stripe CLI, `stripe login` e depois
  ```
  stripe listen --forward-to localhost:8000/api/stripe/webhook --events customer.subscription.created,customer.subscription.updated,customer.subscription.deleted,customer.deleted,invoice.payment_succeeded,invoice.payment_failed
  ```
  Copiar o `whsec_…` mostrado para `STRIPE_WEBHOOK_SECRET`. Mantém-se enquanto o login da CLI for válido; se mudar, atualizar o `.env` e `php artisan config:clear` (senão os webhooks locais dão 403). Para gerar eventos: `stripe trigger invoice.payment_failed`.
- **Versão da API dos webhooks:** o código lê os eventos no formato **`2025-06-30.basil`** (a versão do Cashier 16.8). Ao criar o endpoint no dashboard (servidor), escolher essa versão. O `stripe listen` usa a versão por omissão da conta: confirmar em Developers → versão da API e verificar o `api_version` de um evento no `stripe listen`. Com uma versão anterior, as cobranças falhadas não abrem o período de carência e o log mostra "Falha de pagamento num formato antigo da API do Stripe; carência não aberta.".
- **`stripe trigger invoice.payment_succeeded`:** prova a assinatura e a idempotência (aparece uma linha em `processed_webhook_events`), mas o cliente é criado pela CLI e não é nosso, por isso não há fatura (só um aviso no log). É normal.
- **Ver uma fatura** (com `stripe listen` e `php artisan queue:work` a correr), em `php artisan tinker`:
  ```php
  $u = App\Models\User::factory()->withBilling()->create();
  $u->newSubscription('default', config('subscription.price_id'))->create('pm_card_visa');
  ```
  A linha em `invoices` passa a `issued` e o `storage/logs/laravel.log` tem "Fatura emitida (driver log)". Para limpar: `$u->subscription('default')->cancelNow(); $u->anonymizeAndDelete();` (a fatura fica: obrigação fiscal).
- **Fila:** com `QUEUE_CONNECTION=database`, sem `php artisan queue:work` as faturas ficam `pending`.
- **Pagamentos falhados (dashboard do Stripe → Billing → definições de pagamentos falhados):** Smart Retries com janela de **2 semanas** e, no fim, **cancelar a subscrição**. Porquê 2 semanas: quem termina a carência é o nosso comando `subscriptions:end-grace` (7 dias), que chega sempre primeiro; o cancelamento do Stripe só atua como rede de segurança se o scheduler não estiver a correr (o webhook `customer.subscription.deleted` desativa então a conta).
- **Billing Portal (atualizar o cartão):** `STRIPE_BILLING_PORTAL_CONFIGURATION` é **obrigatória fora de `local`/`testing`** (sem ela o portal responde 500 e a exceção fica no log); em `local` é opcional (usa a configuração por omissão do dashboard), mas convém configurá-la para a demo mostrar só "atualizar cartão". O membro abre o portal com `POST /api/subscription/billing-portal` e volta a `{FRONTEND_URL}/conta/subscricao`. Passo a passo (modo de teste; repetir em live com as chaves live, porque as configurações não são partilhadas entre modos):
  - **Opção A — Stripe CLI (recomendada, cria uma configuração própria):**
    ```bash
    stripe billing_portal configurations create \
      -d "features[payment_method_update][enabled]=true" \
      -d "features[subscription_cancel][enabled]=false" \
      -d "features[subscription_update][enabled]=false" \
      -d "features[customer_update][enabled]=false" \
      -d "features[invoice_history][enabled]=false" \
      -d "business_profile[headline]=ASPEC — atualizar o cartão"
    ```
    Copiar o `id` (`bpc_…`) da resposta.
  - **Opção B — dashboard:** Settings → Billing → Customer portal: ligar só **atualizar o método de pagamento**; desligar **cancelar subscrição**, **mudar de plano**, **editar dados do cliente** (email, morada, NIF) e **histórico de faturas**; guardar. Obter o id com `stripe billing_portal configurations list` (a que tem `"is_default": true`). Nota: se alguém mudar a configuração por omissão no dashboard, este id muda de opções também, por isso é preferível a opção A.
  - Pôr `STRIPE_BILLING_PORTAL_CONFIGURATION=bpc_…` no `.env` e correr `php artisan config:clear`.
  - Confirmar: `stripe billing_portal configurations retrieve bpc_…` → só `payment_method_update.enabled = true`.
  - Porquê desligar o resto: as faturas fiscais saem da InvoiceExpress com os dados de faturação guardados na plataforma, por isso alterá-los no Stripe não mudava as faturas; o cancelamento é feito na plataforma (`POST /api/subscription/cancel`), um só caminho com as nossas regras e emails.
  - Cartão novo (verificado a 11/10 em modo de teste): o portal muda o cartão predefinido do **cliente** (`invoice_settings.default_payment_method`); a subscrição fica com `default_payment_method: null` e por isso a próxima cobrança usa o cartão novo. Nada a fazer do nosso lado.
- **Cartões de teste:** `4242 4242 4242 4242` (sucesso), `4000 0000 0000 0341` (é aceite mas a cobrança falha → período de carência); qualquer data futura e qualquer CVC.
- **Test clocks:** só servem para clientes criados dentro de um clock; os clientes criados pelo Checkout não estão, por isso a demo usa o guião "Ciclo de vida da subscrição" abaixo.
- **Processos em segundo plano:** `php artisan queue:work` (faturas, apagar o cliente Stripe, emails) e o scheduler (fim da carência, de hora a hora): `php artisan schedule:work` em local; no servidor, um cron `* * * * * php artisan schedule:run`. Para a demo, `php artisan subscriptions:end-grace` corre o fim da carência à mão.
- **Depois de `git pull`:** `composer install` e `php artisan migrate`.

### Ativação da conta (demo ponta a ponta)

1. Correr ao mesmo tempo: `php artisan serve`, `php artisan queue:work` (sem ele os emails não saem), o `stripe listen` acima e o frontend (`http://localhost:5173`).
2. `FRONTEND_URL` no `.env` (por omissão `http://localhost:5173`) define o domínio dos links enviados por email.
3. Registar um membro e aprová-lo no backoffice ou com `PATCH /api/admin/users/{id}/approve`. A conta fica **Approved** (sem acesso) e é enviado o email de ativação.
4. Com `MAIL_MAILER=log`, abrir o link do email no `storage/logs/laravel.log` (`{FRONTEND_URL}/ativacao/{id}?expires=…&signature=…`), preencher os dados de faturação e seguir para o Stripe Checkout.
5. Pagar com o cartão `4242 4242 4242 4242`. O webhook `customer.subscription.created` passa a conta a **Active** em segundos (com o fim do período experimental em `users.trial_ends_at`) e envia o email de boas-vindas; o login passa a funcionar.

O link vale 7 dias (`ACTIVATION_LINK_DAYS` / `REACTIVATION_LINK_DAYS`) e pode ser aberto várias vezes: repetir o pedido com um Checkout ainda aberto devolve o mesmo pagamento. Desbloquear uma conta também envia o link (ativação, se nunca subscreveu; reativação, sem novo período experimental, se já subscreveu).

**Reenviar o link:** se o link expirou ou o email se perdeu, a página `/ativacao/novo-link` do frontend pede o email e chama `POST /api/account-activations/resend` (público). Contas Approved recebem um novo link de ativação e contas inativas por falta de pagamento ou com a subscrição cancelada um de reativação; a resposta é sempre a mesma, exista a conta ou não. Limites: no máximo 5 reenvios por email por dia (de qualquer IP), 3 em 15 min por email+IP e 10/h por IP. Para testar: pedir o reenvio para o email de uma conta Approved e abrir o novo link no `storage/logs/laravel.log` (com `php artisan queue:work` a correr); com um email inexistente a resposta é igual e nada aparece no log.

### Período de carência e cancelamento

- **Carência:** a 1.ª cobrança falhada (`invoice.payment_failed`) abre um período de carência de `SUBSCRIPTION_GRACE_DAYS` dias (7 por omissão) em `users.grace_ends_at` e envia um email com o prazo e o botão "Atualizar cartão" (`/conta/subscricao`). O membro mantém o acesso total. As novas tentativas do Stripe não prolongam o prazo. Um pagamento recebido (`invoice.payment_succeeded` ou subscrição de volta a `active`) termina a carência.
- **Fim da carência:** o comando `subscriptions:end-grace` (agendado de hora a hora) confirma no Stripe o estado de cada conta com o prazo expirado. Se estiver paga, só limpa a carência; senão a conta fica **Inactive** (`unpaid`): sem login, fora do diretório, com email de reativação (Checkout sem novo período experimental). Depois, a subscrição é cancelada no Stripe. Se o Stripe não responder, a conta fica como está e é tentada na hora seguinte.
- **Cancelamento pelo membro:** em `/conta/subscricao` (`POST /api/subscription/cancel`) o cancelamento fica agendado para o fim do período pago (ou do trial), sem reembolso; o acesso mantém-se até lá e pode ser retomado (`POST /api/subscription/resume`). Os dois pedidos enviam um email de confirmação. No fim, o webhook `customer.subscription.deleted` passa a conta a **Inactive** (`cancelled`) e envia o email "A sua subscrição ASPEC terminou" com o link de reativação. Com um pagamento em atraso o cancelamento é recusado (409): a subscrição já termina no fim da carência se não for paga.
- **Bloqueio:** uma conta bloqueada nunca recebe estes emails nem muda de motivo (os handlers só mexem em contas Active).

### Ciclo de vida da subscrição (teste manual)

Com `php artisan serve`, `php artisan queue:work`, o `stripe listen` acima e `MAIL_MAILER=log`:

1. Ativar um membro (guião acima) com o cartão `4000 0000 0000 0341` (aceite no Checkout; a cobrança falha).
2. Terminar o trial já: `stripe subscriptions update sub_… -d trial_end=now` → `invoice.payment_failed` → `GET /api/subscription` mostra `past_due` e `grace_ends_at`; email de falha no log.
3. Portal (`POST /api/subscription/billing-portal`) → mudar para `4242 4242 4242 4242`. Ver no dashboard se a fatura em atraso é cobrada logo ou só na tentativa seguinte; para não esperar, `stripe invoices pay in_…` → `invoice.payment_succeeded` → `grace_ends_at` null e fatura `issued`.
4. Repetir 1–2; em `php artisan tinker` pôr `grace_ends_at` no passado (`User::where('email', '…')->first()->forceFill(['grace_ends_at' => now()->subDay()])->save();`) e correr `php artisan subscriptions:end-grace` → conta Inactive (`unpaid`), login 403, email de reativação no log; no Stripe a subscrição fica cancelada.
5. Abrir o link de reativação → Checkout sem trial com `4242…` → conta Active, sem trial.
6. `POST /api/subscription/cancel` → `cancel_at_period_end: true` + email; `POST /api/subscription/resume` → `false` + email; cancelar outra vez e terminar já (`stripe subscriptions cancel sub_…` ou "cancelar já" no dashboard) → `customer.subscription.deleted` → conta Inactive (`cancelled`), email "A sua subscrição ASPEC terminou"; reativar pelo link → Active.
7. Bloquear um membro em carência → continua `blocked`, sem email de reativação (o `customer.subscription.deleted` do cancelamento é ignorado).

### Faturas do membro

- **Drivers (`INVOICING_DRIVER`):** `log` (por omissão; só regista a emissão no log, sem PDF), `demo` e `invoiceexpress`.
- **Driver `demo` (apresentação):** cada pagamento gera um PDF "DOCUMENTO DE DEMONSTRAÇÃO — SEM VALIDADE FISCAL" com os dados de faturação do membro, guardado em `storage/app/private/invoices/{user_id}/DEMO-AAAA-XXXXXXXX.pdf` (disco privado, nunca em `public/storage`), e envia-o por email em anexo. Precisa de `php artisan queue:work` a correr.
- **Email das faturas:** `MAIL_MAILER=log` é só para desenvolvimento local: o email da fatura (com o PDF, que tem NIF e morada) fica escrito no `storage/logs/laravel.log`, por isso limpar esse ficheiro depois de testar. Na apresentação usar um SMTP de teste (Mailpit, Mailtrap ou uma conta de email com password de aplicação).
- **Endpoints (membro Active):** `GET /api/invoices` (10 por página, mais recentes primeiro; `status` `issued` ou `processing`; `pdf_available` diz se há PDF) e `GET /api/invoices/{id}/pdf` (descarrega o PDF; 10 por minuto). Faturas e PDFs nunca são apagados, nem na anonimização da conta (obrigação fiscal de 10 anos).
- **Teste manual:** `INVOICING_DRIVER=demo` e `MAIL_MAILER=log` no `.env` + `php artisan config:clear`; ativar um membro (cartão `4242…`) → fatura `issued` com número `DEMO-…`; abrir o PDF (acentos e € corretos); no Postman, "Listar faturas" e "Descarregar PDF da fatura" com "Send and Download".
- **InvoiceExpress:** o trial gratuito de 30 dias serve de sandbox (sem comunicação à AT). Criá-lo perto da apresentação (~2/11, para cobrir a semana de 30/11), copiar o nome da conta e a chave da API para `INVOICEEXPRESS_ACCOUNT_NAME` / `INVOICEEXPRESS_API_KEY` e mudar para `INVOICING_DRIVER=invoiceexpress`. "Limpar Conta" (definições da InvoiceExpress) apaga os documentos de teste. As faturas já emitidas com `demo` continuam a descarregar-se pelo driver que as emitiu.

## Diretório

- **Endpoints públicos** (sem login; limite partilhado de 60 pedidos por minuto por IP):
  - `GET /api/members` — lista paginada, ordenada por empresa, nome e id. Filtros opcionais e combináveis: `search` (nome, empresa ou descrição; até 100 caracteres), `sector_id`, `location_id` (distrito), `min_rating` (1 a 5; devolve uma lista vazia enquanto não existirem avaliações), `page` e `per_page` (12 por omissão, máximo 50). Cada item traz `id`, `name`, `business_name`, `description` (texto completo ou `null`; o corte para o cartão é feito no frontend), `sector`, `location`, `logo_url`, `average_rating` e `reviews_count`.
  - `GET /api/members/{id}` — perfil público (`id` = id do perfil, o mesmo da lista).
- **Quem aparece:** contas **Active** com perfil de membro, seja qual for o papel (um admin com perfil aparece; um admin sem perfil nunca). Contas Pending, Approved, Inactive ou apagadas não aparecem e o perfil devolve 404 "Membro não encontrado.", igual a um id que não existe. Contas em trial ou em período de carência continuam a aparecer.
- **Contactos:** o telefone e os contactos comerciais só vêm em `members_only` para quem consulta com uma conta Active (sessão do frontend ou token Bearer); para os outros `members_only` é `null`. Morada, website, horário, redes sociais e portfólio são públicos. O email da conta e a congregação nunca aparecem no diretório.
