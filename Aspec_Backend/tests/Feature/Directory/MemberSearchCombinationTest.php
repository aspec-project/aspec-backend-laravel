<?php

namespace Tests\Feature\Directory;

use App\Enums\InactiveReason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsDirectoryMembers;
use Tests\Concerns\BuildsReviews;
use Tests\TestCase;

/**
 * Pesquisa avançada do diretório: min_rating combinado com setor, distrito e palavras-chave,
 * ordenação e paginação. Os autores das avaliações não têm avaliações recebidas, por isso
 * nunca passam o min_rating e não interferem nos totais.
 */
class MemberSearchCombinationTest extends TestCase
{
    use BuildsDirectoryMembers, BuildsReviews, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    #[Test]
    public function search_sector_location_and_min_rating_combine(): void
    {
        $sector = $this->secondSector()->id;
        $location = $this->secondLocation()->id;
        $match = $this->listedMember(['sector_id' => $sector, 'location_id' => $location, 'business_name' => 'Padaria Norte']);
        $lowRating = $this->listedMember(['sector_id' => $sector, 'location_id' => $location, 'business_name' => 'Padaria Fraca']);
        $otherText = $this->listedMember(['sector_id' => $sector, 'location_id' => $location, 'business_name' => 'Talho Norte']);
        $otherSector = $this->listedMember(['sector_id' => $this->firstSector()->id, 'location_id' => $location, 'business_name' => 'Padaria Este']);
        $otherLocation = $this->listedMember(['sector_id' => $sector, 'location_id' => $this->firstLocation()->id, 'business_name' => 'Padaria Sul']);
        $this->reviewsFor($match, [5, 4]);
        $this->reviewsFor($lowRating, [3, 3]);
        foreach ([$otherText, $otherSector, $otherLocation] as $profile) {
            $this->reviewsFor($profile, [5, 5]);
        }

        $response = $this->listMembers([
            'search' => 'Padaria',
            'sector_id' => $sector,
            'location_id' => $location,
            'min_rating' => 4,
        ])->assertOk()->assertJsonPath('data.pagination.total', 1);

        $this->assertSame([$match->id], $this->listedIds($response));
    }

    #[Test]
    public function sector_and_min_rating_combine(): void
    {
        $match = $this->listedMember(['sector_id' => $this->secondSector()->id]);
        $lowRating = $this->listedMember(['sector_id' => $this->secondSector()->id]);
        $otherSector = $this->listedMember(['sector_id' => $this->firstSector()->id]);
        $this->reviewsFor($match, [4]);
        $this->reviewsFor($lowRating, [2]);
        $this->reviewsFor($otherSector, [5]);

        $response = $this->listMembers(['sector_id' => $this->secondSector()->id, 'min_rating' => 4])->assertOk();

        $this->assertSame([$match->id], $this->listedIds($response));
    }

    #[Test]
    public function location_and_min_rating_combine(): void
    {
        $match = $this->listedMember(['location_id' => $this->secondLocation()->id]);
        $lowRating = $this->listedMember(['location_id' => $this->secondLocation()->id]);
        $otherLocation = $this->listedMember(['location_id' => $this->firstLocation()->id]);
        $this->reviewsFor($match, [4]);
        $this->reviewsFor($lowRating, [2]);
        $this->reviewsFor($otherLocation, [5]);

        $response = $this->listMembers(['location_id' => $this->secondLocation()->id, 'min_rating' => 4])->assertOk();

        $this->assertSame([$match->id], $this->listedIds($response));
    }

    #[Test]
    public function search_and_min_rating_combine(): void
    {
        $match = $this->listedMember(['business_name' => 'Padaria Sol']);
        $lowRating = $this->listedMember(['business_name' => 'Padaria Lua']);
        $otherText = $this->listedMember(['business_name' => 'Talho Sol']);
        $this->reviewsFor($match, [4]);
        $this->reviewsFor($lowRating, [2]);
        $this->reviewsFor($otherText, [5]);

        $response = $this->listMembers(['search' => 'Padaria', 'min_rating' => 4])->assertOk();

        $this->assertSame([$match->id], $this->listedIds($response));
    }

    #[Test]
    public function min_rating_keeps_the_alphabetical_order_instead_of_sorting_by_rating(): void
    {
        $charlie = $this->listedMember(['business_name' => 'Charlie']);
        $alfa = $this->listedMember(['business_name' => 'Alfa']);
        $bravo = $this->listedMember(['business_name' => 'Bravo']);
        $this->reviewsFor($charlie, [5]);
        $this->reviewsFor($alfa, [4]);
        $this->reviewsFor($bravo, [5, 4]);

        $response = $this->listMembers(['min_rating' => 4])->assertOk();

        $this->assertSame([$alfa->id, $bravo->id, $charlie->id], $this->listedIds($response));
    }

    #[Test]
    public function min_rating_total_and_last_page_count_only_the_matching_profiles(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            $this->reviewsFor($this->listedMember(['business_name' => sprintf('Boa %02d', $i)]), [4]);
        }
        for ($i = 1; $i <= 5; $i++) {
            $this->reviewsFor($this->listedMember(['business_name' => sprintf('Fraca %02d', $i)]), [3]);
        }

        $this->listMembers(['min_rating' => 4, 'per_page' => 12])
            ->assertOk()
            ->assertJsonCount(12, 'data.items')
            ->assertJsonPath('data.pagination', [
                'current_page' => 1,
                'per_page' => 12,
                'total' => 13,
                'last_page' => 2,
            ]);

        $this->listMembers(['min_rating' => 4, 'per_page' => 12, 'page' => 2])
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.business_name', 'Boa 13')
            ->assertJsonPath('data.pagination.current_page', 2);
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function accountsThatAreNotListed(): array
    {
        return [
            'Pending' => ['pending', null],
            'Approved' => ['approved', null],
            'Inactive blocked' => ['inactive', 'blocked'],
            'Inactive unpaid' => ['inactive', 'unpaid'],
            'Inactive cancelled' => ['inactive', 'cancelled'],
        ];
    }

    #[Test]
    #[DataProvider('accountsThatAreNotListed')]
    public function highly_rated_profile_of_an_account_that_is_not_active_is_never_listed(string $state, ?string $reason): void
    {
        $owner = $state === 'inactive'
            ? User::factory()->inactive(InactiveReason::from($reason))->create()
            : User::factory()->{$state}()->create();
        $hidden = $this->profileFor($owner, ['business_name' => 'Padaria Escondida']);
        $listed = $this->listedMember(['business_name' => 'Padaria Visivel']);
        $this->reviewsFor($hidden, [5, 5, 5]);
        $this->reviewsFor($listed, [4]);

        $this->assertSame([$listed->id], $this->listedIds($this->listMembers(['min_rating' => 4])->assertOk()));
        $this->assertSame([$listed->id], $this->listedIds($this->listMembers(['search' => 'Padaria'])->assertOk()));
        $this->showMember($hidden->id)->assertNotFound()->assertExactJson(self::NOT_FOUND_BODY);
    }
}
