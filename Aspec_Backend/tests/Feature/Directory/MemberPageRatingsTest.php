<?php

namespace Tests\Feature\Directory;

use App\Models\MemberProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsDirectoryMembers;
use Tests\Concerns\BuildsReviews;
use Tests\TestCase;

/**
 * Média e contagem das avaliações calculadas só para os perfis da página (GET /api/members).
 *
 * Com milhares de perfis, calcular a média de todos antes de paginar tornava o diretório lento;
 * a lista passa a calculá-la numa consulta extra só para os perfis devolvidos, sem mudar a resposta.
 * Os perfis dos testes têm "Zqx" no nome e a pesquisa filtra por esse termo, porque os autores
 * das avaliações (criados pelo ReviewFactory) também aparecem no diretório com nomes aleatórios.
 */
class MemberPageRatingsTest extends TestCase
{
    use BuildsDirectoryMembers, BuildsReviews, RefreshDatabase;

    private const SEARCH = 'Zqx';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    #[Test]
    public function profile_on_a_later_page_counts_only_visible_reviews_and_matches_the_public_profile(): void
    {
        $first = $this->targetProfile(1);
        $this->targetProfile(2);
        $rated = $this->targetProfile(3);
        $unrated = $this->targetProfile(4);
        $this->targetProfile(5);
        $this->reviewsFor($first, [2]);
        $this->reviewsFor($rated, [4, 5, 4]);
        $this->reviewsFor($rated, [1], 'hidden');
        $this->reviewsFor($rated, [1], 'flagged');

        $response = $this->listMembers(['search' => self::SEARCH, 'per_page' => 2, 'page' => 2])
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 5);

        $this->assertSame([$rated->id, $unrated->id], $this->listedIds($response));

        $ratedItem = $this->listedItem($response, $rated->id);
        $this->assertSame(4.3, $ratedItem['average_rating']);
        $this->assertSame(3, $ratedItem['reviews_count']);

        $unratedItem = $this->listedItem($response, $unrated->id);
        $this->assertNull($unratedItem['average_rating']);
        $this->assertSame(0, $unratedItem['reviews_count']);

        $this->showMember($rated->id)
            ->assertOk()
            ->assertJsonPath('data.average_rating', $ratedItem['average_rating'])
            ->assertJsonPath('data.reviews_count', $ratedItem['reviews_count']);

        $this->showMember($unrated->id)
            ->assertOk()
            ->assertJsonPath('data.average_rating', null)
            ->assertJsonPath('data.reviews_count', 0);
    }

    #[Test]
    public function each_profile_of_the_page_gets_its_own_average_and_count(): void
    {
        $expected = [
            1 => [[5], 5.0, 1],
            2 => [[1, 2], 1.5, 2],
            3 => [[3, 3, 4], 3.3, 3],
            4 => [[2, 5, 5, 5], 4.3, 4],
            5 => [[], null, 0],
            6 => [[4, 4], 4.0, 2],
        ];
        $profiles = [];
        foreach ($expected as $position => [$ratings]) {
            $profiles[$position] = $this->targetProfile($position);
            $this->reviewsFor($profiles[$position], $ratings);
        }

        $response = $this->listMembers(['search' => self::SEARCH, 'per_page' => 3, 'page' => 2])->assertOk();

        $this->assertSame([$profiles[4]->id, $profiles[5]->id, $profiles[6]->id], $this->listedIds($response));
        foreach ([4, 5, 6] as $position) {
            [, $average, $count] = $expected[$position];
            $item = $this->listedItem($response, $profiles[$position]->id);

            // Uma média exata sai no JSON como inteiro (4), por isso compara-se o valor e não o tipo.
            $this->assertEquals($average, $item['average_rating'], "Média do perfil {$position}");
            $this->assertSame($count, $item['reviews_count'], "Contagem do perfil {$position}");
        }
    }

    #[Test]
    public function min_rating_with_pagination_returns_the_same_profiles_totals_and_ratings_on_every_page(): void
    {
        $matching = [
            $this->targetProfile(1, [5, 4]),
            $this->targetProfile(3, [4]),
            $this->targetProfile(4, [5, 5, 4]),
            $this->targetProfile(6, [4, 4, 5]),
            $this->targetProfile(7, [5]),
        ];
        $this->targetProfile(2, [3, 4]);
        $this->targetProfile(5);
        $this->reviewsFor($matching[1], [1], 'hidden');

        $expectedRatings = [4.5, 4.0, 4.7, 4.3, 5.0];
        $listed = [];
        for ($page = 1; $page <= 3; $page++) {
            $response = $this->listMembers(['search' => self::SEARCH, 'min_rating' => 4, 'per_page' => 2, 'page' => $page])
                ->assertOk()
                ->assertJsonPath('data.pagination.total', 5)
                ->assertJsonPath('data.pagination.last_page', 3)
                ->assertJsonPath('data.pagination.current_page', $page);

            $listed = [...$listed, ...$response->json('data.items')];
        }

        $this->assertSame(array_map(fn (MemberProfile $profile) => $profile->id, $matching), array_column($listed, 'id'));
        foreach ($expectedRatings as $index => $average) {
            $this->assertEquals($average, $listed[$index]['average_rating']);
        }
        $this->assertSame([2, 1, 3, 3, 1], array_column($listed, 'reviews_count'));
    }

    #[Test]
    public function number_of_queries_is_the_same_with_three_and_with_thirty_rated_profiles(): void
    {
        for ($position = 1; $position <= 3; $position++) {
            $this->targetProfile($position, [4, 5]);
        }
        $withThree = $this->countQueries(fn () => $this->listMembers(['search' => self::SEARCH, 'per_page' => 50])
            ->assertOk()
            ->assertJsonCount(3, 'data.items'));

        for ($position = 4; $position <= 30; $position++) {
            $this->targetProfile($position, [3, 4, 5]);
        }
        $withThirty = $this->countQueries(fn () => $this->listMembers(['search' => self::SEARCH, 'per_page' => 50])
            ->assertOk()
            ->assertJsonCount(30, 'data.items'));

        $this->assertSame($withThree, $withThirty);
    }

    #[Test]
    public function ratings_are_computed_in_one_extra_query_and_not_in_the_paginated_query(): void
    {
        $this->targetProfile(1, [4, 5]);
        $this->targetProfile(2, [3]);

        $queries = $this->capturedQueries(fn () => $this->listMembers(['search' => self::SEARCH])->assertOk());

        $reviewQueries = array_values(array_filter($queries, fn (string $sql) => str_contains($sql, 'reviews')));
        $this->assertCount(1, $reviewQueries, implode("\n", $reviewQueries));
        $this->assertStringNotContainsStringIgnoringCase('limit', $reviewQueries[0]);
    }

    #[Test]
    public function visitor_list_request_uses_five_queries(): void
    {
        $this->targetProfile(1, [4, 5]);
        $this->targetProfile(2, [3]);

        $this->assertCount(5, $this->capturedQueries(fn () => $this->listMembers(['search' => self::SEARCH])->assertOk()));
    }

    #[Test]
    public function page_beyond_the_last_returns_empty_items_without_computing_ratings(): void
    {
        $this->targetProfile(1, [4, 5]);

        $queries = $this->capturedQueries(fn () => $this->listMembers(['search' => self::SEARCH, 'page' => 3])
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.pagination.total', 1));

        $this->assertSame([], array_values(array_filter($queries, fn (string $sql) => str_contains($sql, 'reviews'))));
    }

    /**
     * Perfil do diretório com "Zqx" e a posição no nome da empresa, para a ordem alfabética ser a da posição.
     *
     * @param  list<int>  $visibleRatings
     */
    private function targetProfile(int $position, array $visibleRatings = []): MemberProfile
    {
        $profile = $this->listedMember(['business_name' => sprintf('%s Empresa %02d', self::SEARCH, $position)]);
        $this->reviewsFor($profile, $visibleRatings);

        return $profile;
    }

    /**
     * @return list<string>
     */
    private function capturedQueries(callable $request): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $queries;
    }

    private function countQueries(callable $request): int
    {
        return count($this->capturedQueries($request));
    }
}
