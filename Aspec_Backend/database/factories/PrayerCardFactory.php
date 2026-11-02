<?php

namespace Database\Factories;

use App\Models\EventInstance;
use App\Models\PrayerCard;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Cartão de oração preenchido num evento com cartões ativos, ainda sem destinatário.
 * O autor por omissão é uma conta Active sem perfil: os testes que precisam do nome criam o perfil.
 *
 * @extends Factory<PrayerCard>
 */
class PrayerCardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_instance_id' => EventInstance::factory()->state([
                'prayer_cards_enabled' => true,
                'prayer_verse' => 'Filipenses 4:6',
            ]),
            'author_user_id' => User::factory(),
            'recipient_user_id' => null,
            'personal_request' => fake()->sentence(),
            'work_request' => fake()->sentence(),
            'salvation_request' => fake()->sentence(),
            'consent_given_at' => now(),
            'received_at' => null,
        ];
    }

    /**
     * Cartão já entregue a outro membro na distribuição em cadeia.
     */
    public function distributed(): static
    {
        return $this->state(fn () => [
            'recipient_user_id' => User::factory(),
            'received_at' => now(),
        ]);
    }

    /**
     * Cartão expirado ou retirado: conteúdo apagado e soft delete (a linha fica só como presença).
     */
    public function expired(): static
    {
        return $this->state(fn () => [
            'personal_request' => null,
            'work_request' => null,
            'salvation_request' => null,
            'deleted_at' => now(),
        ]);
    }
}
