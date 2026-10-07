<?php

namespace Database\Factories;

use App\Enums\InactiveReason;
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
            // Telemóvel português válido para a regra PortuguesePhone (91, 92, 93 ou 96 + 7 dígitos).
            'phone' => fake()->randomElement(['91', '92', '93', '96']).fake()->numerify('#######'),
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
     * Conta aprovada pelo administrador, à espera da ativação (cartão).
     * O motivo de inatividade fica null, como em qualquer conta que não é Inactive.
     */
    public function approved(): static
    {
        return $this->withStatus('Approved')->state(fn () => [
            'inactive_reason' => null,
        ]);
    }

    /**
     * Conta inativa (recusada, bloqueada ou sem pagamento), com motivo opcional.
     * Sem motivo, o inactive_reason fica null (conta inativa antiga).
     */
    public function inactive(?InactiveReason $reason = null): static
    {
        return $this->withStatus('Inactive')->state(fn () => [
            'inactive_reason' => $reason,
        ]);
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
 