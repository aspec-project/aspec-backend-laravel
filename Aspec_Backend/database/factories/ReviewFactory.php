<?php

namespace Database\Factories;

use App\Models\MemberProfile;
use App\Models\Review;
use App\Models\ReviewStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Avaliação Visible entre dois perfis diferentes (cada um com a sua conta Active).
 * Requer os lookups seeded (review_statuses, além dos do MemberProfileFactory).
 *
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reviewer_profile_id' => MemberProfile::factory(),
            'reviewed_profile_id' => MemberProfile::factory(),
            'rating' => fake()->numberBetween(1, 5),
            'comment' => fake()->randomElement([
                'Excelente serviço, recomendo.',
                'Muito profissional e pontual.',
                'Bom atendimento, voltarei a contactar.',
                'Trabalho cuidado e preço justo.',
            ]),
            'status_id' => fn () => self::statusId('Visible'),
        ];
    }

    public function visible(): static
    {
        return $this->state(fn () => ['status_id' => self::statusId('Visible')]);
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['status_id' => self::statusId('Hidden')]);
    }

    public function flagged(): static
    {
        return $this->state(fn () => ['status_id' => self::statusId('Flagged')]);
    }

    private static function statusId(string $name): string
    {
        return ReviewStatus::where('name', $name)->valueOrFail('id');
    }
}
