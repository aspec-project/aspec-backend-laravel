<?php

namespace Tests\Feature\Directory;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsDirectoryMembers;
use Tests\TestCase;

/**
 * Lista pública do diretório (GET /api/members).
 *
 * O contrato (chaves do item, eco de page/per_page, last_page = max(1, ceil(total / per_page)))
 * é validado de forma estrita pelo frontend: qualquer desvio faz a página mostrar um erro.
 */
class MemberIndexTest extends TestCase
{
    use BuildsDirectoryMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    #[Test]
    public function visitor_gets_list_with_exact_item_contract(): void
    {
        $profile = $this->listedMember(['name' => 'Ana Silva', 'business_name' => 'Silva Lda']);
        $sector = $this->firstSector();
        $location = $this->firstLocation();

        $response = $this->listMembers()
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Membros obtidos com sucesso.')
            ->assertJsonPath('data.items.0.id', $profile->id)
            ->assertJsonPath('data.items.0.name', 'Ana Silva')
            ->assertJsonPath('data.items.0.business_name', 'Silva Lda')
            ->assertJsonPath('data.items.0.sector', ['id' => $sector->id, 'name' => $sector->name])
            ->assertJsonPath('data.items.0.location', ['id' => $location->id, 'name' => $location->name])
            ->assertJsonPath('data.items.0.logo_url', null)
            ->assertJsonPath('data.items.0.average_rating', null)
            ->assertJsonPath('data.items.0.reviews_count', 0);

        $this->assertEqualsCanonicalizing(['items', 'pagination'], array_keys($response->json('data')));
        $this->assertEqualsCanonicalizing(
            ['current_page', 'per_page', 'total', 'last_page'],
            array_keys($response->json('data.pagination')),
        );
        $this->assertEqualsCanonicalizing(self::LIST_ITEM_KEYS, array_keys($response->json('data.items.0')));
    }

    #[Test]
    public function list_is_paginated_by_twelve_by_default(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            $this->listedMember(['business_name' => sprintf('Empresa %02d', $i)]);
        }

        $first = $this->listMembers()->assertOk()->assertJsonCount(12, 'data.items');
        $this->assertSame(
            ['current_page' => 1, 'per_page' => 12, 'total' => 13, 'last_page' => 2],
            $first->json('data.pagination'),
        );

        $this->listMembers(['page' => 2])
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.business_name', 'Empresa 13')
            ->assertJsonPath('data.pagination.current_page', 2);
    }

    #[Test]
    public function per_page_is_echoed_back_and_fifty_is_accepted(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->listedMember(['business_name' => "Empresa {$i}"]);
        }

        $this->listMembers(['per_page' => 5])
            ->assertOk()
            ->assertJsonCount(5, 'data.items')
            ->assertJsonPath('data.pagination.per_page', 5)
            ->assertJsonPath('data.pagination.last_page', 2);

        $this->listMembers(['per_page' => 50])
            ->assertOk()
            ->assertJsonCount(6, 'data.items')
            ->assertJsonPath('data.pagination.per_page', 50)
            ->assertJsonPath('data.pagination.last_page', 1);
    }

    /**
     * Um per_page vazio chega como null (ConvertEmptyStringsToNull) e tem de cair nos 12 do
     * diretório, não nos 15 que o paginate() usa por omissão.
     */
    #[Test]
    public function empty_per_page_uses_the_default_of_twelve(): void
    {
        for ($i = 1; $i <= 16; $i++) {
            $this->listedMember(['business_name' => sprintf('Empresa %02d', $i)]);
        }

        foreach (['?per_page=', '?page=1&per_page='] as $query) {
            $this->getJson(self::MEMBERS_URL.$query)
                ->assertOk()
                ->assertJsonCount(12, 'data.items')
                ->assertJsonPath('data.pagination', [
                    'current_page' => 1,
                    'per_page' => 12,
                    'total' => 16,
                    'last_page' => 2,
                ]);
        }
    }

    #[Test]
    public function empty_directory_returns_first_and_last_page_one(): void
    {
        $this->listMembers()
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.pagination', [
                'current_page' => 1,
                'per_page' => 12,
                'total' => 0,
                'last_page' => 1,
            ]);
    }

    #[Test]
    public function page_beyond_the_last_returns_empty_items_and_echoes_the_requested_page(): void
    {
        $this->listedMember();

        $this->listMembers(['page' => 3])
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.pagination.current_page', 3)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.pagination.last_page', 1);
    }

    #[Test]
    public function list_is_sorted_by_business_name_then_name_then_id_without_repeats_across_pages(): void
    {
        $beta = $this->listedMember(['business_name' => 'Beta', 'name' => 'Ana']);
        $alfaRui = $this->listedMember(['business_name' => 'Alfa', 'name' => 'Rui']);
        $alfaAna = $this->listedMember(['business_name' => 'Alfa', 'name' => 'Ana']);
        $tieA = $this->listedMember(['business_name' => 'Gama', 'name' => 'Igual']);
        $tieB = $this->listedMember(['business_name' => 'Gama', 'name' => 'Igual']);

        $ties = [$tieA->id, $tieB->id];
        sort($ties, SORT_STRING);
        $expected = [$alfaAna->id, $alfaRui->id, $beta->id, ...$ties];

        $this->assertSame($expected, $this->listedIds($this->listMembers()->assertOk()));

        $paged = [];
        for ($page = 1; $page <= 3; $page++) {
            $paged = [...$paged, ...$this->listedIds($this->listMembers(['page' => $page, 'per_page' => 2])->assertOk())];
        }

        $this->assertSame($expected, $paged);
    }

    #[Test]
    public function search_matches_name_business_name_and_description(): void
    {
        $byName = $this->listedMember(['name' => 'Joao Padeiro', 'business_name' => 'Alfa']);
        $byBusiness = $this->listedMember(['name' => 'Rui', 'business_name' => 'Padeiro Lda']);
        $byDescription = $this->listedMember(['name' => 'Ana', 'business_name' => 'Beta', 'description' => 'Somos padeiro artesanal.']);
        $this->listedMember(['name' => 'Outro', 'business_name' => 'Gama', 'description' => 'Contabilidade.']);

        $response = $this->listMembers(['search' => 'Padeiro'])->assertOk()->assertJsonPath('data.pagination.total', 3);

        $this->assertEqualsCanonicalizing(
            [$byName->id, $byBusiness->id, $byDescription->id],
            $this->listedIds($response),
        );
    }

    #[Test]
    public function search_ignores_letter_case(): void
    {
        $acme = $this->listedMember(['business_name' => 'Acme Lda']);
        $this->listedMember(['business_name' => 'Outra Lda']);

        $this->assertSame([$acme->id], $this->listedIds($this->listMembers(['search' => 'ACME'])->assertOk()));
    }

    #[Test]
    public function search_treats_like_wildcards_and_escape_character_literally(): void
    {
        $percent = $this->listedMember(['business_name' => 'Desconto 50% Lda']);
        $underscore = $this->listedMember(['business_name' => 'Loja_Central']);
        $bang = $this->listedMember(['business_name' => 'Alerta! Lda']);
        $this->listedMember(['business_name' => 'Loja Central']);
        $this->listedMember(['business_name' => 'Empresa Normal']);

        $this->assertSame([$percent->id], $this->listedIds($this->listMembers(['search' => '%'])->assertOk()));
        $this->assertSame([$underscore->id], $this->listedIds($this->listMembers(['search' => '_'])->assertOk()));
        $this->assertSame([$bang->id], $this->listedIds($this->listMembers(['search' => '!'])->assertOk()));
    }

    #[Test]
    public function search_never_returns_an_inactive_profile_with_the_same_name(): void
    {
        $active = $this->listedMember(['business_name' => 'Padaria Sol']);
        $this->profileFor(User::factory()->inactive(), ['business_name' => 'Padaria Sol']);

        $this->listMembers(['search' => 'Padaria'])
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $active->id);
    }

    #[Test]
    public function sector_filter_returns_only_that_sector(): void
    {
        $inSector = $this->listedMember(['sector_id' => $this->secondSector()->id]);
        $this->listedMember(['sector_id' => $this->firstSector()->id]);

        $this->assertSame(
            [$inSector->id],
            $this->listedIds($this->listMembers(['sector_id' => $this->secondSector()->id])->assertOk()),
        );
    }

    #[Test]
    public function location_filter_returns_only_that_district(): void
    {
        $inLocation = $this->listedMember(['location_id' => $this->secondLocation()->id]);
        $this->listedMember(['location_id' => $this->firstLocation()->id]);

        $this->assertSame(
            [$inLocation->id],
            $this->listedIds($this->listMembers(['location_id' => $this->secondLocation()->id])->assertOk()),
        );
    }

    #[Test]
    public function sector_location_and_search_filters_combine(): void
    {
        $sector = $this->secondSector()->id;
        $location = $this->secondLocation()->id;
        $match = $this->listedMember(['sector_id' => $sector, 'location_id' => $location, 'business_name' => 'Padaria Norte']);
        $this->listedMember(['sector_id' => $sector, 'location_id' => $location, 'business_name' => 'Talho Norte']);
        $this->listedMember(['sector_id' => $sector, 'location_id' => $this->firstLocation()->id, 'business_name' => 'Padaria Sul']);
        $this->listedMember(['sector_id' => $this->firstSector()->id, 'location_id' => $location, 'business_name' => 'Padaria Este']);

        $response = $this->listMembers(['sector_id' => $sector, 'location_id' => $location, 'search' => 'Padaria'])
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        $this->assertSame([$match->id], $this->listedIds($response));
    }

    #[Test]
    public function min_rating_returns_an_empty_list_while_there_are_no_reviews(): void
    {
        $this->listedMember();
        $this->listedMember();

        $this->listMembers(['min_rating' => 4])
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.pagination.total', 0)
            ->assertJsonPath('data.pagination.last_page', 1);
    }

    #[Test]
    public function every_item_has_null_average_rating_and_zero_reviews_while_there_are_no_reviews(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->listedMember(['business_name' => "Empresa {$i}"]);
        }

        $items = $this->listMembers()->assertOk()->json('data.items');

        $this->assertCount(3, $items);
        foreach ($items as $item) {
            $this->assertNull($item['average_rating']);
            $this->assertSame(0, $item['reviews_count']);
        }
    }

    #[Test]
    public function logo_url_is_the_public_url_of_the_logo_or_null(): void
    {
        $path = 'logos/abc/logo.png';
        $withLogo = $this->listedMember(['business_name' => 'Alfa', 'logo_path' => $path]);
        $withoutLogo = $this->listedMember(['business_name' => 'Beta', 'logo_path' => null]);

        $this->listMembers()
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $withLogo->id)
            ->assertJsonPath('data.items.0.logo_url', Storage::disk('public')->url($path))
            ->assertJsonPath('data.items.1.id', $withoutLogo->id)
            ->assertJsonPath('data.items.1.logo_url', null);
    }

    #[Test]
    public function description_is_the_full_profile_description_or_null(): void
    {
        $description = str_repeat('Consultoria e formacao para empresas. ', 26);
        $withDescription = $this->listedMember(['business_name' => 'Alfa', 'description' => $description]);
        $withoutDescription = $this->listedMember(['business_name' => 'Beta', 'description' => null]);

        $this->listMembers()
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $withDescription->id)
            ->assertJsonPath('data.items.0.description', $description)
            ->assertJsonPath('data.items.1.id', $withoutDescription->id)
            ->assertJsonPath('data.items.1.description', null);
    }

    #[Test]
    public function number_of_queries_does_not_grow_with_the_number_of_members(): void
    {
        $this->listedMember(['sector_id' => $this->firstSector()->id, 'location_id' => $this->firstLocation()->id]);
        $this->listedMember(['sector_id' => $this->secondSector()->id, 'location_id' => $this->secondLocation()->id]);
        $withTwo = $this->countQueries(fn () => $this->listMembers()->assertOk()->assertJsonCount(2, 'data.items'));

        for ($i = 1; $i <= 10; $i++) {
            $this->listedMember([
                'sector_id' => $i % 2 ? $this->firstSector()->id : $this->secondSector()->id,
                'location_id' => $i % 2 ? $this->secondLocation()->id : $this->firstLocation()->id,
            ]);
        }
        $withTwelve = $this->countQueries(fn () => $this->listMembers()->assertOk()->assertJsonCount(12, 'data.items'));

        $this->assertSame($withTwo, $withTwelve);
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
