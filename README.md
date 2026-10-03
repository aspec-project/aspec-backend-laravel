# aspec-backend-laravel

## Setup local

Na pasta `Aspec_Backend/`:

1. Copiar `.env.example` para `.env` e definir `APP_URL` com o endereço onde a API corre (ex. `http://localhost:8000` com `php artisan serve`, ou o domínio do Laragon). Os URLs das imagens (logótipos e portfólio) são gerados a partir do `APP_URL`; se estiver errado, as imagens não abrem no frontend.
2. `php artisan key:generate`
3. `php artisan migrate:fresh --seed` — cria as tabelas e os dados de referência (roles, estados de conta, setores, distritos, dias da semana, redes sociais). **Apaga todos os dados da base de dados local.**
4. `php artisan storage:link` — cria o atalho `public/storage` → `storage/app/public`. Sem ele, os ficheiros enviados (`logos/{user_id}`, `portfolios/{user_id}`) não ficam acessíveis por URL. O atalho é local a cada máquina e não vai para o git (`public/storage` está no `.gitignore`).
