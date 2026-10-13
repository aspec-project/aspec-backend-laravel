<?php

namespace Tests\Feature\Directory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsDirectoryMembers;
use Tests\TestCase;

/**
 * Validação dos filtros da lista do diretório (GET /api/members).
 */
class MemberIndexValidationTest extends TestCase
{
    use BuildsDirectoryMembers, RefreshDatabase;

    private function assertRejected(string $query, string $field): void
    {
        $this->getJson(self::MEMBERS_URL.'?'.$query)
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors([$field]);
    }

    #[Test]
    public function sector_id_that_is_not_a_uuid_is_rejected(): void
    {
        $this->assertRejected('sector_id=abc', 'sector_id');
    }

    #[Test]
    public function sector_id_that_does_not_exist_is_rejected(): void
    {
        $this->assertRejected('sector_id='.Str::uuid(), 'sector_id');
    }

    #[Test]
    public function location_id_that_is_not_a_uuid_or_does_not_exist_is_rejected(): void
    {
        $this->assertRejected('location_id=abc', 'location_id');
        $this->assertRejected('location_id='.Str::uuid(), 'location_id');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidMinRatings(): array
    {
        return [
            'abaixo de 1' => ['0'],
            'acima de 5' => ['6'],
            'não numérico' => ['abc'],
        ];
    }

    #[Test]
    #[DataProvider('invalidMinRatings')]
    public function min_rating_outside_one_to_five_is_rejected(string $value): void
    {
        $this->assertRejected('min_rating='.$value, 'min_rating');
    }

    #[Test]
    public function decimal_min_rating_is_accepted(): void
    {
        $this->listMembers(['min_rating' => '4.5'])->assertOk();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPerPages(): array
    {
        return [
            'zero' => ['0'],
            'acima de 50' => ['51'],
            'não numérico' => ['abc'],
        ];
    }

    #[Test]
    #[DataProvider('invalidPerPages')]
    public function per_page_outside_one_to_fifty_is_rejected(string $value): void
    {
        $this->assertRejected('per_page='.$value, 'per_page');
    }

    #[Test]
    public function page_zero_is_rejected(): void
    {
        $this->assertRejected('page=0', 'page');
    }

    /**
     * Uma página enorme dava 500 (o deslocamento deixa de caber num inteiro) quando havia membros listados.
     */
    #[Test]
    public function huge_page_is_rejected_instead_of_failing(): void
    {
        $this->listedMember();

        $this->assertRejected('page=999999999999999999', 'page');
        $this->assertRejected('page=10001', 'page');
    }

    #[Test]
    public function last_allowed_page_is_accepted(): void
    {
        $this->getJson('/api/members?page=10000')->assertOk();
    }

    #[Test]
    public function search_longer_than_one_hundred_characters_is_rejected(): void
    {
        $this->assertRejected('search='.str_repeat('a', 101), 'search');
    }

    #[Test]
    public function search_of_one_hundred_characters_is_accepted(): void
    {
        $this->listMembers(['search' => str_repeat('a', 100)])->assertOk();
    }

    #[Test]
    public function search_sent_as_an_array_is_rejected(): void
    {
        $this->assertRejected('search[]=x', 'search');
    }

    #[Test]
    public function blank_search_is_ignored(): void
    {
        $this->listedMember(['business_name' => 'Alfa']);
        $this->listedMember(['business_name' => 'Beta']);

        $this->getJson(self::MEMBERS_URL.'?search='.rawurlencode('   '))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2);
    }
}
