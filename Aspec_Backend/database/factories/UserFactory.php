<?php

namespace Database\Factories;

use App\Models\AccountStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * A password usada pela factory (gerada uma vez, para os testes serem rápidos).
     */
    protected static ?string $password;

    /**
     * Estado por omissão: membro ativo com a password "password".
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'phone' => fake()->numerify('9########'),
            'role_id' => fn () => Role::firstOrCreate(['name' => 'Member'])->id,
            'account_status_id' => fn () => AccountStatus::firstOrCreate(['name' => 'Active'])->id,
            'trial_ends_at' => null,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Utilizador com o papel Admin.
     */
    public function admin(): static
    {
        return $this->state(fn () => [
            'role_id' => Role::firstOrCreate(['name' => 'Admin'])->id,
        ]);
    }

    /**
     * Conta à espera de aprovação.
     */
    public function pending(): static
    {
        return $this->withStatus('Pending');
    }

    /**
     * Conta inativa (recusada, bloqueada ou sem pagamento).
     */
    public function inactive(): static
    {
        return $this->withStatus('Inactive');
    }

    /**
     * Email por verificar.
     */
    public function unverified(): static
    {
        return $this->state(fn () => [
            'email_verified_at' => null,
        ]);
    }

    private function withStatus(string $status): static
    {
        return $this->state(fn () => [
            'account_status_id' => AccountStatus::firstOrCreate(['name' => $status])->id,
        ]);
    }
}
 