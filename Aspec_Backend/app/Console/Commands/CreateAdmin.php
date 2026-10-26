<?php

namespace App\Console\Commands;

use App\Models\AccountStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'aspec:create-admin';

    protected $description = 'Cria o administrador técnico (isento de subscrição) com o email e a password do .env';

    /**
     * Cria o administrador técnico: Admin, Active, sem perfil e o único isento de subscrição.
     * Idempotente para o mesmo email. Recusa um segundo administrador técnico e nunca isenta uma
     * conta que já existe (um admin que paga continua a pagar).
     *
     * @return int SUCCESS se o administrador técnico foi criado ou já existia; FAILURE caso contrário.
     */
    public function handle(): int
    {
        $email = config('aspec.admin.email');
        $password = config('aspec.admin.password');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            [
                'email' => ['required', 'email'],
                'password' => ['required', Password::default()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            $this->line('Defina ASPEC_ADMIN_EMAIL e ASPEC_ADMIN_PASSWORD no .env.');

            return self::FAILURE;
        }

        $roleId = Role::where('name', 'Admin')->value('id');
        $statusId = AccountStatus::where('name', 'Active')->value('id');

        if (! $roleId || ! $statusId) {
            $this->error('Faltam os papéis ou estados de conta. Corra primeiro: php artisan db:seed');

            return self::FAILURE;
        }

        $existing = User::withTrashed()
            ->with(['role', 'accountStatus'])
            ->where('email', $email)
            ->first();

        if ($existing) {
            $isActiveAdmin = ! $existing->trashed()
                && $existing->role?->name === 'Admin'
                && $existing->accountStatus?->name === 'Active';

            if ($isActiveAdmin && $existing->isSubscriptionExempt()) {
                $this->info('O administrador já existe. Nada a fazer.');

                return self::SUCCESS;
            }

            if ($isActiveAdmin) {
                $this->error('Já existe uma conta com este email que não é o administrador técnico.');

                return self::FAILURE;
            }

            $this->error('Já existe uma conta com este email que não é um administrador ativo.');

            return self::FAILURE;
        }

        if (User::subscriptionExempt()->exists()) {
            $this->error('Já existe um administrador técnico.');

            return self::FAILURE;
        }

        (new User)->forceFill([
            'email' => $email,
            'password' => $password,
            'role_id' => $roleId,
            'account_status_id' => $statusId,
            'subscription_exempt' => true,
        ])->save();

        $this->info("Administrador {$email} criado.");

        return self::SUCCESS;
    }
}
