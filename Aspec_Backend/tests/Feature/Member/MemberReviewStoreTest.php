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

class MemberReviewStoreTest extends TestCase
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

    private function url(MemberProfile $profile): string
    {
        return "/api/members/{$profile->id}/reviews";
    }

    private function validPayload(): array
    {
        return [
            'rating' => 5,
            'comment' => 'Excelente serviço e comunicação.',
        ];
    }

    #[Test]
    public function active_member_can_review_another_member(): void
    {
        [$reviewer, $reviewerProfile] = $this->memberWithProfile();
        [, $reviewedProfile] = $this->memberWithProfile();

        Sanctum::actingAs($reviewer);

        $response = $this->postJson($this->url($reviewedProfile), $this->validPayload());

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.comment', 'Excelente serviço e comunicação.')
            ->assertJsonPath('data.reviewer.id', $reviewerProfile->id)
            ->assertJsonPath('data.reviewer.business_name', $reviewerProfile->business_name);

        $this->assertDatabaseHas('reviews', [
            'reviewer_profile_id' => $reviewerProfile->id,
            'reviewed_profile_id' => $reviewedProfile->id,
            'rating' => 5,
            'comment' => 'Excelente serviço e comunicação.',
            'status_id' => ReviewStatus::query()->where('name', 'Visible')->value('id'),
        ]);
    }

    #[Test]
    public function active_admin_with_profile_can_review_another_member(): void
    {
        $admin = User::factory()->admin()->create([
            'account_status_id' => AccountStatus::query()->where('name', 'Active')->value('id'),
        ]);

        $adminProfile = MemberProfile::factory()->create([
            'user_id' => $admin->id,
        ]);

        [, $reviewedProfile] = $this->memberWithProfile();

        Sanctum::actingAs($admin);

        $this->postJson($this->url($reviewedProfile), $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.rating', 5);

        $this->assertDatabaseHas('reviews', [
            'reviewer_profile_id' => $adminProfile->id,
            'reviewed_profile_id' => $reviewedProfile->id,
            'rating' => 5,
            'comment' => 'Excelente serviço e comunicação.',
        ]);
    }

    #[Test]
    public function posting_again_updates_the_existing_review_instead_of_duplicating_it(): void
    {
        [$reviewer, $reviewerProfile] = $this->memberWithProfile();
        [, $reviewedProfile] = $this->memberWithProfile();

        Sanctum::actingAs($reviewer);

        $this->postJson($this->url($reviewedProfile), $this->validPayload())
            ->assertCreated();

        $this->postJson($this->url($reviewedProfile), [
            'rating' => 3,
            'comment' => 'Atualização do comentário.',
        ])
            ->assertOk()
            ->assertJsonPath('data.rating', 3)
            ->assertJsonPath('data.comment', 'Atualização do comentário.');

        $this->assertDatabaseCount('reviews', 1);
        $this->assertDatabaseHas('reviews', [
            'reviewer_profile_id' => $reviewerProfile->id,
            'reviewed_profile_id' => $reviewedProfile->id,
            'rating' => 3,
            'comment' => 'Atualização do comentário.',
        ]);
    }

    #[Test]
    public function updating_a_review_does_not_change_its_hidden_or_flagged_status(): void
    {
        foreach (['Hidden', 'Flagged'] as $statusName) {
            [$reviewer, $reviewerProfile] = $this->memberWithProfile();
            [, $reviewedProfile] = $this->memberWithProfile();

            $statusId = ReviewStatus::query()
                ->where('name', $statusName)
                ->value('id');

            Review::query()->create([
                'reviewer_profile_id' => $reviewerProfile->id,
                'reviewed_profile_id' => $reviewedProfile->id,
                'rating' => 2,
                'comment' => 'Comentário original.',
                'status_id' => $statusId,
            ]);

            Sanctum::actingAs($reviewer);

            $this->postJson($this->url($reviewedProfile), [
                'rating' => 4,
                'comment' => 'Comentário atualizado.',
            ])->assertOk();

            $this->assertDatabaseHas('reviews', [
                'reviewer_profile_id' => $reviewerProfile->id,
                'reviewed_profile_id' => $reviewedProfile->id,
                'rating' => 4,
                'comment' => 'Comentário atualizado.',
                'status_id' => $statusId,
            ]);
        }
    }

    #[Test]
    public function member_cannot_review_their_own_profile(): void
    {
        [$member, $profile] = $this->memberWithProfile();

        Sanctum::actingAs($member);

        $this->postJson($this->url($profile), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('reviews', 0);
    }

    #[Test]
    public function guests_and_pending_members_cannot_submit_reviews(): void
    {
        [, $reviewedProfile] = $this->memberWithProfile();

        $this->postJson($this->url($reviewedProfile), $this->validPayload())
            ->assertUnauthorized();

        [$pending] = $this->memberWithProfile('Pending');
        Sanctum::actingAs($pending);

        $this->postJson($this->url($reviewedProfile), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('reviews', 0);
    }

    #[Test]
    public function review_submission_over_the_minute_limit_is_throttled(): void
    {
        [$reviewer] = $this->memberWithProfile();
        [, $reviewedProfile] = $this->memberWithProfile();

        Sanctum::actingAs($reviewer);
        $this->fillRateLimit('review-submit', 'minute:'.$reviewer->id, 120);

        $this->postJson($this->url($reviewedProfile), $this->validPayload())
            ->assertTooManyRequests()
            ->assertExactJson([
                'success' => false,
                'message' => 'Demasiados pedidos. Tente novamente mais tarde.',
            ]);

        $this->assertDatabaseCount('reviews', 0);
    }

    #[Test]
    public function rating_and_comment_are_required_and_validated(): void
    {
        [$reviewer] = $this->memberWithProfile();
        [, $reviewedProfile] = $this->memberWithProfile();

        Sanctum::actingAs($reviewer);

        foreach ([
            ['rating' => 0, 'comment' => 'Inválido.'],
            ['rating' => 6, 'comment' => 'Inválido.'],
            ['rating' => 4, 'comment' => ''],
            ['rating' => 4],
        ] as $payload) {
            $this->postJson($this->url($reviewedProfile), $payload)
                ->assertUnprocessable();
        }

        $this->assertDatabaseCount('reviews', 0);
    }
}
