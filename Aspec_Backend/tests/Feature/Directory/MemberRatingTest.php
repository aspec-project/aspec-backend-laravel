<?php

namespace Tests\Feature\Directory;

use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsDirectoryMembers;
use Tests\Concerns\BuildsReviews;
use Tests\TestCase;

/**
 * Média das avaliações no diretório (GET /api/members) e no perfil público (GET /api/members/{id}).
 *
 * Só as avaliações Visible contam. A média mostrada tem 1 casa decimal e o filtro min_rating
 * compara com essa média arredondada, para um cartão que mostra "4,5" aparecer em "4,5+".
 * As médias dos testes evitam um 5 na 2.ª casa decimal (o ROUND do MySQL e do SQLite podem
 * divergir nesse caso-limite).
 */
class MemberRatingTest extends TestCase
{
    use BuildsDirectoryMembers, BuildsReviews, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    #[Test]
    public function only_visible_reviews_count_for_average_and_count(): void
    {
        $profile = $this->listedMember();
        $this->reviewsFor($profile, [5, 4, 4, 3]);
        $this->reviewsFor($profile, [1], 'hidden');
        $this->reviewsFor($profile, [1], 'flagged');

        $item = $this->listedItem($this->listMembers(['per_page' => 50])->assertOk(), $profile->id);

        // Uma média exata sai no JSON como inteiro (4), por isso compara-se o valor e não o tipo.
        $this->assertEquals(4.0, $item['average_rating']);
        $this->assertSame(4, $item['reviews_count']);
    }

    #[Test]
    public function profile_with_only_hidden_or_flagged_reviews_has_null_average_and_zero_count(): void
    {
        $profile = $this->listedMember();
        $this->reviewsFor($profile, [5, 5], 'hidden');
        $this->reviewsFor($profile, [4], 'flagged');

        $item = $this->listedItem($this->listMembers(['per_page' => 50])->assertOk(), $profile->id);

        $this->assertNotNull($item);
        $this->assertNull($item['average_rating']);
        $this->assertSame(0, $item['reviews_count']);
    }

    #[Test]
    public function average_is_rounded_to_one_decimal_place(): void
    {
        $upward = $this->listedMember(['business_name' => 'Alfa']);
        $downward = $this->listedMember(['business_name' => 'Beta']);
        $single = $this->listedMember(['business_name' => 'Gama']);
        $this->reviewsFor($upward, [4, 5, 5]);
        $this->reviewsFor($downward, [4, 4, 5]);
        $this->reviewsFor($single, [4]);

        $response = $this->listMembers(['per_page' => 50])->assertOk();

        $this->assertSame(4.7, $this->listedItem($response, $upward->id)['average_rating']);
        $this->assertSame(3, $this->listedItem($response, $upward->id)['reviews_count']);
        $this->assertSame(4.3, $this->listedItem($response, $downward->id)['average_rating']);
        $this->assertEquals(4.0, $this->listedItem($response, $single->id)['average_rating']);
        $this->assertSame(1, $this->listedItem($response, $single->id)['reviews_count']);
    }

    /**
     * 67/15 = 4,47 aparece como 4,5 no cartão, por isso tem de entrar em "4,5+"; 4,4 fica de fora.
     */
    #[Test]
    public function min_rating_compares_with_the_average_rounded_to_one_decimal_place(): void
    {
        $roundsUp = $this->listedMember(['business_name' => 'Alfa']);
        $below = $this->listedMember(['business_name' => 'Beta']);
        $this->reviewsFor($roundsUp, [...array_fill(0, 7, 5), ...array_fill(0, 8, 4)]);
        $this->reviewsFor($below, [5, 5, 4, 4, 4]);

        $this->listMembers(['min_rating' => 4.5])
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $roundsUp->id)
            ->assertJsonPath('data.items.0.average_rating', 4.5)
            ->assertJsonPath('data.items.0.reviews_count', 15);
    }

    #[Test]
    public function min_rating_excludes_profiles_without_reviews_and_profiles_rated_high_only_in_hidden_reviews(): void
    {
        $rated = $this->listedMember(['business_name' => 'Alfa']);
        $this->listedMember(['business_name' => 'Sem Avaliacoes']);
        $onlyHidden = $this->listedMember(['business_name' => 'So Ocultas']);
        $hiddenLiftsAverage = $this->listedMember(['business_name' => 'Ocultas Altas']);
        $this->reviewsFor($rated, [4]);
        $this->reviewsFor($onlyHidden, [5, 5], 'hidden');
        $this->reviewsFor($hiddenLiftsAverage, [2]);
        $this->reviewsFor($hiddenLiftsAverage, [5, 5, 5], 'hidden');
        $this->reviewsFor($hiddenLiftsAverage, [5, 5, 5], 'flagged');

        $response = $this->listMembers(['min_rating' => 4])
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        $this->assertSame([$rated->id], $this->listedIds($response));
    }

    #[Test]
    public function visible_review_written_by_an_anonymised_member_still_counts(): void
    {
        $profile = $this->listedMember();
        $author = $this->listedMember(['business_name' => 'Autor']);
        Review::factory()->create([
            'reviewer_profile_id' => $author->id,
            'reviewed_profile_id' => $profile->id,
            'rating' => 2,
        ]);
        $this->reviewsFor($profile, [5]);

        $author->user->anonymizeAndDelete();

        $this->assertSoftDeleted('member_profiles', ['id' => $author->id]);
        $this->assertDatabaseHas('reviews', ['reviewer_profile_id' => $author->id, 'reviewed_profile_id' => $profile->id]);

        $this->showMember($profile->id)
            ->assertOk()
            ->assertJsonPath('data.average_rating', 3.5)
            ->assertJsonPath('data.reviews_count', 2);
    }

    #[Test]
    public function public_profile_shows_the_same_average_and_count_as_the_list_for_visitors_and_members(): void
    {
        $profile = $this->listedMember();
        $this->reviewsFor($profile, [4, 4, 5]);
        $this->reviewsFor($profile, [1], 'hidden');

        $item = $this->listedItem($this->listMembers(['per_page' => 50])->assertOk(), $profile->id);
        $this->assertSame(4.3, $item['average_rating']);
        $this->assertSame(3, $item['reviews_count']);

        $this->showMember($profile->id)
            ->assertOk()
            ->assertJsonPath('data.average_rating', $item['average_rating'])
            ->assertJsonPath('data.reviews_count', $item['reviews_count']);

        $viewer = $this->listedMember(['business_name' => 'Quem Consulta'])->user;
        $this->app['auth']->forgetGuards();

        $this->withToken($viewer->createToken('postman')->plainTextToken)
            ->showMember($profile->id)
            ->assertOk()
            ->assertJsonPath('data.members_only.address', $profile->address)
            ->assertJsonPath('data.average_rating', $item['average_rating'])
            ->assertJsonPath('data.reviews_count', $item['reviews_count']);
    }

    #[Test]
    public function list_query_count_does_not_grow_when_members_have_reviews(): void
    {
        $first = $this->listedMember(['business_name' => 'Alfa']);
        $second = $this->listedMember(['business_name' => 'Beta']);
        $query = ['per_page' => 50];

        $withoutReviews = $this->countQueries(fn () => $this->listMembers($query)->assertOk());

        $this->reviewsFor($first, [5, 4, 3]);
        $this->reviewsFor($second, [2, 4]);
        $this->reviewsFor($second, [1], 'hidden');

        $withReviews = $this->countQueries(fn () => $this->listMembers($query)->assertOk());

        $this->assertSame($withoutReviews, $withReviews);
    }

    #[Test]
    public function public_profile_query_count_does_not_grow_when_the_member_has_reviews(): void
    {
        $this->freezeTime();
        $empty = $this->listedMember(['business_name' => 'Sem Avaliacoes']);
        $rated = $this->listedMember(['business_name' => 'Com Avaliacoes']);
        $this->reviewsFor($rated, [5, 4, 3, 2]);
        $this->reviewsFor($rated, [1], 'flagged');
        $token = $this->listedMember(['business_name' => 'Quem Consulta'])->user->createToken('postman')->plainTextToken;

        // Com o tempo congelado, o 1.º pedido grava o last_used_at do token e os seguintes já não o alteram.
        $this->withToken($token)->showMember($empty->id)->assertOk();

        $this->assertSame(
            $this->countQueries(fn () => $this->withToken($token)->showMember($empty->id)->assertOk()),
            $this->countQueries(fn () => $this->withToken($token)->showMember($rated->id)
                ->assertOk()
                ->assertJsonPath('data.reviews_count', 4)),
        );
    }

    private function countQueries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
