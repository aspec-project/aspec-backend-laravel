<?php

namespace Tests\Feature\Member;

use App\Models\AccountStatus;
use App\Models\MemberProfile;
use App\Models\Review;
use App\Models\ReviewStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FillsRateLimits;
use Tests\TestCase;

class MemberReviewDestroyTest extends TestCase
{
    use FillsRateLimits;
    use RefreshDatabase;

    private function memberWithProfile(string $status = 'Active'): array
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('name', 'Member')->value('id'),
            'account_status_id' => AccountStatus::query()->where('name', $status)->value('id'),
        ]);

        $profile = MemberProfile::factory()->create([
            'user_id' => $user->id,
        ]);

        return [$user, $profile];
    }

    private function createReview(
        MemberProfile $reviewer,
        MemberProfile $reviewed,
        string $statusName = 'Visible'
    ): Review {
        return Review::query()->create([
            'reviewer_profile_id' => $reviewer->id,
            'reviewed_profile_id' => $reviewed->id,
            'rating' => 5,
            'comment' => "Avaliação {$statusName}.",
            'status_id' => ReviewStatus::query()
                ->where('name', $statusName)
                ->valueOrFail('id'),
        ]);
    }

    private function url(MemberProfile $profile): string
    {
        return "/api/members/{$profile->id}/reviews";
    }

    #[Test]
    public function member_can_delete_their_own_review_in_any_status(): void
    {
        [$reviewer, $reviewerProfile] = $this->memberWithProfile();
        Sanctum::actingAs($reviewer);

        foreach (['Visible', 'Hidden', 'Flagged'] as $statusName) {
            [, $reviewedProfile] = $this->memberWithProfile();

            $review = $this->createReview(
                $reviewerProfile,
                $reviewedProfile,
                $statusName
            );

            $this->deleteJson($this->url($reviewedProfile))
                ->assertOk()
                ->assertExactJson([
                    'success' => true,
                    'message' => 'Avaliação apagada com sucesso.',
                    'data' => null,
                ]);

            $this->assertDatabaseMissing('reviews', [
                'id' => $review->id,
            ]);
        }
    }

    #[Test]
    public function member_cannot_delete_another_members_review(): void
    {
        [, $ownerProfile] = $this->memberWithProfile();
        [, $reviewedProfile] = $this->memberWithProfile();
        [$requester] = $this->memberWithProfile();

        $review = $this->createReview($ownerProfile, $reviewedProfile);

        Sanctum::actingAs($requester);

        $this->deleteJson($this->url($reviewedProfile))
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Avaliação não encontrada.',
            ]);

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
        ]);
    }

    #[Test]
    public function deleting_when_the_member_has_no_review_returns_not_found(): void
    {
        [$reviewer] = $this->memberWithProfile();
        [, $reviewedProfile] = $this->memberWithProfile();

        Sanctum::actingAs($reviewer);

        $this->deleteJson($this->url($reviewedProfile))
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Avaliação não encontrada.',
            ]);

        $this->assertDatabaseCount('reviews', 0);
    }

    #[Test]
    public function delete_requires_authentication(): void
    {
        [, $reviewedProfile] = $this->memberWithProfile();

        $this->deleteJson($this->url($reviewedProfile))
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'Não autenticado.',
            ]);
    }

    #[Test]
    public function pending_member_cannot_delete_a_review(): void
    {
        [$reviewer, $reviewerProfile] = $this->memberWithProfile('Pending');
        [, $reviewedProfile] = $this->memberWithProfile();

        $review = $this->createReview($reviewerProfile, $reviewedProfile);

        Sanctum::actingAs($reviewer);

        $this->deleteJson($this->url($reviewedProfile))
            ->assertForbidden();

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
        ]);
    }

    #[Test]
    public function active_admin_with_profile_can_delete_their_own_review(): void
    {
        $admin = User::factory()->admin()->create([
            'account_status_id' => AccountStatus::query()->where('name', 'Active')->value('id'),
        ]);

        $adminProfile = MemberProfile::factory()->create([
            'user_id' => $admin->id,
        ]);

        [, $reviewedProfile] = $this->memberWithProfile();
        $review = $this->createReview($adminProfile, $reviewedProfile);

        Sanctum::actingAs($admin);

        $this->deleteJson($this->url($reviewedProfile))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('reviews', [
            'id' => $review->id,
        ]);
    }

    #[Test]
    public function delete_over_the_minute_limit_is_throttled(): void
    {
        [$reviewer, $reviewerProfile] = $this->memberWithProfile();
        [, $reviewedProfile] = $this->memberWithProfile();

        $review = $this->createReview($reviewerProfile, $reviewedProfile);

        Sanctum::actingAs($reviewer);
        $this->fillRateLimit('review-delete', 'minute:'.$reviewer->id, 120);

        $this->deleteJson($this->url($reviewedProfile))
            ->assertTooManyRequests()
            ->assertExactJson([
                'success' => false,
                'message' => 'Demasiados pedidos. Tente novamente mais tarde.',
            ]);

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
        ]);
    }
}
