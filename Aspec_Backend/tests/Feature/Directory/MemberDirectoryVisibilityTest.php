<?php

namespace Tests\Feature\Directory;

use App\Enums\InactiveReason;
use App\Models\MemberProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsDirectoryMembers;
use Tests\TestCase;

/**
 * Quem aparece no diretório: conta Active com perfil não apagado, seja qual for o papel.
 *
 * Cada caso é provado em três sítios: o scope do modelo, a lista e o perfil público,
 * porque a regra tem de ser a mesma nos três (uma conta que sai da lista não pode
 * continuar acessível pelo id).
 */
class MemberDirectoryVisibilityTest extends TestCase
{
    use BuildsDirectoryMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function surfaces(): array
    {
        return [
            'scope' => ['scope'],
            'lista' => ['list'],
            'perfil' => ['profile'],
        ];
    }

    /**
     * @return array<string, array{string, ?string, string}>
     */
    public static function hiddenStatesOnEverySurface(): array
    {
        $states = [
            'Pending' => ['pending', null],
            'Approved' => ['approved', null],
            'Inactive rejected' => ['inactive', 'rejected'],
            'Inactive blocked' => ['inactive', 'blocked'],
            'Inactive unpaid' => ['inactive', 'unpaid'],
            'Inactive cancelled' => ['inactive', 'cancelled'],
        ];

        $cases = [];
        foreach ($states as $label => [$state, $reason]) {
            foreach (self::surfaces() as $surfaceLabel => [$surface]) {
                $cases["{$label} / {$surfaceLabel}"] = [$state, $reason, $surface];
            }
        }

        return $cases;
    }

    private function assertVisibleOn(string $surface, string $profileId): void
    {
        match ($surface) {
            'scope' => $this->assertTrue(
                MemberProfile::listedInDirectory()->whereKey($profileId)->exists(),
                'O perfil devia estar no scope do diretório.',
            ),
            'list' => $this->assertContains($profileId, $this->listedIds($this->listMembers(['per_page' => 50])->assertOk())),
            'profile' => $this->showMember($profileId)->assertOk()->assertJsonPath('data.id', $profileId),
        };
    }

    private function assertHiddenOn(string $surface, string $profileId): void
    {
        match ($surface) {
            'scope' => $this->assertFalse(
                MemberProfile::listedInDirectory()->whereKey($profileId)->exists(),
                'O perfil não devia estar no scope do diretório.',
            ),
            'list' => $this->assertNotContains($profileId, $this->listedIds($this->listMembers(['per_page' => 50])->assertOk())),
            'profile' => $this->showMember($profileId)->assertNotFound()->assertExactJson(self::NOT_FOUND_BODY),
        };
    }

    #[Test]
    #[DataProvider('surfaces')]
    public function active_member_with_profile_is_listed(string $surface): void
    {
        $profile = $this->listedMember();

        $this->assertVisibleOn($surface, $profile->id);
    }

    #[Test]
    #[DataProvider('hiddenStatesOnEverySurface')]
    public function account_that_is_not_active_is_hidden(string $state, ?string $reason, string $surface): void
    {
        $factory = $state === 'inactive'
            ? User::factory()->inactive(InactiveReason::from($reason))
            : User::factory()->{$state}();
        $profile = $this->profileFor($factory);

        $this->assertHiddenOn($surface, $profile->id);
    }

    #[Test]
    #[DataProvider('surfaces')]
    public function anonymized_member_is_hidden(string $surface): void
    {
        $profile = $this->listedMember();
        $profile->user->anonymizeAndDelete();

        $this->assertHiddenOn($surface, $profile->id);
    }

    #[Test]
    #[DataProvider('surfaces')]
    public function soft_deleted_profile_of_active_user_is_hidden(string $surface): void
    {
        $profile = $this->listedMember();
        $profile->delete();

        $this->assertHiddenOn($surface, $profile->id);
    }

    #[Test]
    #[DataProvider('surfaces')]
    public function soft_deleted_user_without_anonymization_is_hidden(string $surface): void
    {
        $profile = $this->listedMember();
        $profile->user->delete();

        $this->assertHiddenOn($surface, $profile->id);
    }

    #[Test]
    #[DataProvider('surfaces')]
    public function active_admin_with_member_profile_is_listed_like_any_member(string $surface): void
    {
        $profile = $this->profileFor(User::factory()->admin());

        $this->assertVisibleOn($surface, $profile->id);
    }

    #[Test]
    public function admin_without_profile_never_appears_nor_counts_in_total(): void
    {
        User::factory()->admin()->create();
        $member = $this->listedMember();

        $this->assertSame([$member->id], MemberProfile::listedInDirectory()->pluck('id')->all());

        $this->listMembers()
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $member->id)
            ->assertJsonPath('data.pagination.total', 1);
    }

    #[Test]
    #[DataProvider('surfaces')]
    public function active_member_in_grace_period_is_listed(string $surface): void
    {
        $profile = $this->profileFor(User::factory()->state(['grace_ends_at' => now()->addDays(3)]));

        $this->assertVisibleOn($surface, $profile->id);
    }

    #[Test]
    #[DataProvider('surfaces')]
    public function member_deactivated_for_non_payment_disappears_on_the_next_request(string $surface): void
    {
        $profile = $this->listedMember();
        $this->assertVisibleOn($surface, $profile->id);

        $profile->user->deactivate('unpaid');

        $this->assertHiddenOn($surface, $profile->id);
    }
}
