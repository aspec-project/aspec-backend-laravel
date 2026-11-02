<?php

namespace Tests\Concerns;

use App\Models\MemberProfile;
use App\Models\Review;
use Illuminate\Testing\TestResponse;

/**
 * Avaliações recebidas por um perfil do diretório.
 *
 * Cada avaliação tem um autor novo (o par autor/avaliado é único). Os autores têm conta Active e
 * perfil, por isso também aparecem na lista sem min_rating: os testes procuram o item pelo id.
 */
trait BuildsReviews
{
    /**
     * @param  list<int>  $ratings
     * @param  'visible'|'hidden'|'flagged'  $state
     */
    protected function reviewsFor(MemberProfile $profile, array $ratings, string $state = 'visible'): void
    {
        foreach ($ratings as $rating) {
            Review::factory()->{$state}()->create([
                'reviewed_profile_id' => $profile->id,
                'rating' => $rating,
            ]);
        }
    }

    /**
     * Item da lista com o id dado, ou null se o perfil não foi listado.
     *
     * @return array<string, mixed>|null
     */
    protected function listedItem(TestResponse $response, string $id): ?array
    {
        return collect($response->json('data.items'))->firstWhere('id', $id);
    }
}
