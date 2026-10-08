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
