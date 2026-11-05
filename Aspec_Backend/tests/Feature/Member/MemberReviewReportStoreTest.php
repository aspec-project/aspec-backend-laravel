<?php

namespace Tests\Feature\Member;

use App\Models\AccountStatus;
use App\Models\MemberProfile;
use App\Models\Review;
use App\Models\ReviewReport;
use App\Models\ReviewStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberReviewReportStoreTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithProfile(string $status = 'Active'): array
    {
        $user = User::factory()->create([
            'role_id' => Role::query()
                ->where('name', 'Member')
                ->valueOrFail('id'),
            'account_status_id' => AccountStatus::query()
                ->where('name', $status)
                ->valueOrFail('id'),
        ]);

        $profile = MemberProfile::factory()->create([
            'user_id' => $user->id,
        ]);

        return [$user, $profile];
    }

    private function createReview(
        MemberProfile $reviewer,
        MemberProfile $reviewed,
        string $status = 'Visible'
    ): Review {
        return Review::query()->create([
            'reviewer_profile_id' => $reviewer->id,
            'reviewed_profile_id' => $reviewed->id,
            'rating' => 4,
            'comment' => 'Comentário de teste.',
            'status_id' => ReviewStatus::query()
                ->where('name', $status)
                ->valueOrFail('id'),
        ]);
    }

    private function url(Review $review): string
    {
        return "/api/reviews/{$review->id}/reports";
    }

    #[Test]
    public function active_member_can_report_a_visible_review_without_a_comment(): void
    {
        [$reporter, $reporterProfile] = $this->memberWithProfile();
        [, $reviewerProfile] = $this->memberWithProfile();
        [, $reviewedProfile] = $this->memberWithProfile();

        $review = $this->createReview($reviewerProfile, $reviewedProfile);

        Sanctum::actingAs($reporter);

        $this->postJson($this->url($review), [
            'reason' => ReviewReport::REASON_OFFENSIVE_OR_DISRESPECTFUL_CONTENT,
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.review_id', $review->id)
            ->assertJsonPath(
                'data.reason',
                ReviewReport::REASON_OFFENSIVE_OR_DISRESPECTFUL_CONTENT
            )
            ->assertJsonPath('data.comment', null)
            ->assertJsonPath('data.source', ReviewReport::SOURCE_MEMBER);

        $this->assertDatabaseHas('review_reports', [
            'review_id' => $review->id,
            'reporter_profile_id' => $reporterProfile->id,
            'reason' => ReviewReport::REASON_OFFENSIVE_OR_DISRESPECTFUL_CONTENT,
            'comment' => null,
            'source' => ReviewReport::SOURCE_MEMBER,
            'resolved_at' => null,
        ]);

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'status_id' => ReviewStatus::query()
                ->where('name', 'Flagged')
                ->valueOrFail('id'),
        ]);
    }

    #[Test]
    public function another_member_can_report_a_review_already_flagged_by_a_member(): void
    {
        [, $reviewerProfile] = $this->memberWithProfile();
        [, $reviewedProfile] = $this->memberWithProfile();
        [$firstReporter] = $this->memberWithProfile();
        [$secondReporter, $secondReporterProfile] = $this->memberWithProfile();

        $review = $this->createReview($reviewerProfile, $reviewedProfile);

        Sanctum::actingAs($firstReporter);

        $this->postJson($this->url($review), [
            'reason' => ReviewReport::REASON_SPAM_OR_ADVERTISING,
        ])->assertCreated();

        Sanctum::actingAs($secondReporter);

        $this->postJson($this->url($review), [
            'reason' => ReviewReport::REASON_FALSE_OR_UNSUBSTANTIATED_CLAIMS,
            'comment' => 'A afirmação não corresponde ao serviço prestado.',
        ])
            ->assertCreated()
            ->assertJsonPath(
                'data.reason',
                ReviewReport::REASON_FALSE_OR_UNSUBSTANTIATED_CLAIMS
            )
            ->assertJsonPath(
                'data.comment',
                'A afirmação não corresponde ao serviço prestado.'
            );

        $this->assertDatabaseHas('review_reports', [
            'review_id' => $review->id,
            'reporter_profile_id' => $secondReporterProfile->id,
            'reason' => ReviewReport::REASON_FALSE_OR_UNSUBSTANTIATED_CLAIMS,
            'source' => ReviewReport::SOURCE_MEMBER,
            'resolved_at' => null,
        ]);

        $this->assertDatabaseCount('review_reports', 2);

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'status_id' => ReviewStatus::query()
                ->where('name', 'Flagged')
                ->valueOrFail('id'),
        ]);
    }

    #[Test]
    public function member_cannot_create_a_second_pending_report_for_the_same_review(): void
    {
        [$reporter] = $this->memberWithProfile();
        [, $reviewerProfile] = $this->memberWithProfile();
        [, $reviewedProfile] = $this->memberWithProfile();

        $review = $this->createReview($reviewerProfile, $reviewedProfile);

        Sanctum::actingAs($reporter);

        $this->postJson($this->url($review), [
            'reason' => ReviewReport::REASON_SPAM_OR_ADVERTISING,
        ])->assertCreated();

        $this->postJson($this->url($review), [
            'reason' => ReviewReport::REASON_OTHER,
        ])
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'Já sinalizou esta avaliação e o report ainda está pendente.'
            );

        $this->assertDatabaseCount('review_reports', 1);
    }

    #[Test]
    public function member_cannot_report_a_hidden_review_or_their_own_review(): void
    {
        [$member, $memberProfile] = $this->memberWithProfile();
        [, $otherProfile] = $this->memberWithProfile();

        $hiddenReview = $this->createReview(
            $otherProfile,
            $memberProfile,
            'Hidden'
        );

        $ownReview = $this->createReview(
            $memberProfile,
            $otherProfile
        );

        Sanctum::actingAs($member);

        $this->postJson($this->url($hiddenReview), [
            'reason' => ReviewReport::REASON_OTHER,
        ])->assertStatus(409);

        $this->postJson($this->url($ownReview), [
            'reason' => ReviewReport::REASON_OTHER,
        ])->assertForbidden();

        $this->assertDatabaseCount('review_reports', 0);
    }

    #[Test]
    public function report_reason_is_required_and_must_be_one_of_the_allowed_values(): void
    {
        [$member] = $this->memberWithProfile();
        [, $reviewerProfile] = $this->memberWithProfile();
        [, $reviewedProfile] = $this->memberWithProfile();

        $review = $this->createReview($reviewerProfile, $reviewedProfile);

        Sanctum::actingAs($member);

        foreach ([
            [],
            ['reason' => 'motivo não permitido'],
        ] as $payload) {
            $this->postJson($this->url($review), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['reason']);
        }

        $this->assertDatabaseCount('review_reports', 0);
    }

    #[Test]
    public function report_comment_is_optional_but_cannot_exceed_2000_characters(): void
    {
        [$member] = $this->memberWithProfile();
        [, $reviewerProfile] = $this->memberWithProfile();
        [, $reviewedProfile] = $this->memberWithProfile();

        $review = $this->createReview($reviewerProfile, $reviewedProfile);

        Sanctum::actingAs($member);

        $this->postJson($this->url($review), [
            'reason' => ReviewReport::REASON_OTHER,
            'comment' => str_repeat('x', 2001),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['comment']);

        $this->assertDatabaseCount('review_reports', 0);
    }

    #[Test]
    public function only_an_active_member_can_report_a_review(): void
    {
        [, $reviewerProfile] = $this->memberWithProfile();
        [, $reviewedProfile] = $this->memberWithProfile();

        $review = $this->createReview($reviewerProfile, $reviewedProfile);

        $this->postJson($this->url($review), [
            'reason' => ReviewReport::REASON_OTHER,
        ])->assertUnauthorized();

        [$pendingMember] = $this->memberWithProfile('Pending');

        Sanctum::actingAs($pendingMember);

        $this->postJson($this->url($review), [
            'reason' => ReviewReport::REASON_OTHER,
        ])->assertForbidden();

        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin);

        $this->postJson($this->url($review), [
            'reason' => ReviewReport::REASON_OTHER,
        ])->assertForbidden();

        $this->assertDatabaseCount('review_reports', 0);
    }

    #[Test]
    public function active_admin_with_profile_can_report_a_visible_review(): void
    {
        [, $reviewerProfile] = $this->memberWithProfile();
        [, $reviewedProfile] = $this->memberWithProfile();

        $admin = User::factory()->admin()->create();

        $adminProfile = MemberProfile::factory()->create([
            'user_id' => $admin->id,
        ]);

        $review = $this->createReview($reviewerProfile, $reviewedProfile);

        Sanctum::actingAs($admin);

        $this->postJson($this->url($review), [
            'reason' => ReviewReport::REASON_OFFENSIVE_OR_DISRESPECTFUL_CONTENT,
            'comment' => 'A avaliação contém linguagem ofensiva.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.review_id', $review->id)
            ->assertJsonPath('data.source', ReviewReport::SOURCE_MEMBER);

        $this->assertDatabaseHas('review_reports', [
            'review_id' => $review->id,
            'reporter_profile_id' => $adminProfile->id,
            'reason' => ReviewReport::REASON_OFFENSIVE_OR_DISRESPECTFUL_CONTENT,
            'comment' => 'A avaliação contém linguagem ofensiva.',
            'source' => ReviewReport::SOURCE_MEMBER,
            'resolved_at' => null,
        ]);

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'status_id' => ReviewStatus::query()
                ->where('name', 'Flagged')
                ->valueOrFail('id'),
        ]);
    }
}
