<?php

namespace Tests\Feature\Member;

use App\Models\AccountStatus;
use App\Models\MemberProfile;
use App\Models\Review;
use App\Models\ReviewStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberReviewIndexTest extends TestCase
{
    use RefreshDatabase;

    private function profile(): MemberProfile
    {
        $user = User::factory()->create();

        return MemberProfile::factory()->create([
            'user_id' => $user->id,
        ]);
    }

    private function createReview(
        MemberProfile $reviewer,
        MemberProfile $reviewed,
        int $rating,
        string $statusName = 'Visible',
        ?Carbon $createdAt = null
    ): Review {
        $review = Review::query()->create([
            'reviewer_profile_id' => $reviewer->id,
            'reviewed_profile_id' => $reviewed->id,
            'rating' => $rating,
            'comment' => "Avaliação {$rating}",
            'status_id' => ReviewStatus::query()
                ->where('name', $statusName)
                ->value('id'),
        ]);

        if ($createdAt !== null) {
            $review->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->saveQuietly();
        }

        return $review;
    }

    private function url(MemberProfile $profile): string
    {
        return "/api/members/{$profile->id}/reviews";
    }

    private function assertOwnReviewIsFirst(
        User $viewer,
        MemberProfile $ownProfile
    ): void {
        $reviewed = $this->profile();
        $otherReviewer = $this->profile();
        $start = Carbon::parse('2030-01-01T10:00:00+00:00');

        $ownReview = $this->createReview(
            $ownProfile,
            $reviewed,
            3,
            'Visible',
            $start
        );

        $otherReview = $this->createReview(
            $otherReviewer,
            $reviewed,
            5,
            'Visible',
            $start->copy()->addDay()
        );

        Sanctum::actingAs($viewer);

        $this->getJson($this->url($reviewed).'?sort=highest')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $ownReview->id)
            ->assertJsonPath('data.items.0.is_mine', true)
            ->assertJsonPath('data.items.1.id', $otherReview->id)
            ->assertJsonPath('data.items.1.is_mine', false);
    }

    #[Test]
    public function visitor_sees_only_average_and_count_of_visible_reviews(): void
    {
        $reviewed = $this->profile();
        $reviewerOne = $this->profile();
        $reviewerTwo = $this->profile();
        $reviewerThree = $this->profile();
        $reviewerFour = $this->profile();

        $this->createReview($reviewerOne, $reviewed, 2);
        $this->createReview($reviewerTwo, $reviewed, 4);
        $this->createReview($reviewerThree, $reviewed, 5, 'Hidden');
        $this->createReview($reviewerFour, $reviewed, 1, 'Flagged');

        $response = $this->getJson($this->url($reviewed))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.average_rating', 3)
            ->assertJsonPath('data.reviews_count', 2);

        $this->assertEqualsCanonicalizing(
            ['average_rating', 'reviews_count'],
            array_keys($response->json('data'))
        );
    }

    #[Test]
    public function active_admin_sees_visible_reviews_and_distribution(): void
    {
        $reviewed = $this->profile();
        $reviewerOne = $this->profile();
        $reviewerTwo = $this->profile();
        $reviewerThree = $this->profile();

        $this->createReview($reviewerOne, $reviewed, 4);
        $this->createReview($reviewerTwo, $reviewed, 5);
        $this->createReview($reviewerThree, $reviewed, 1, 'Hidden');

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson($this->url($reviewed))
            ->assertOk()
            ->assertJsonPath('data.average_rating', 4.5)
            ->assertJsonPath('data.reviews_count', 2)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.pagination.per_page', 5)
            ->assertJsonPath('data.rating_distribution', [
                ['rating' => 1, 'count' => 0],
                ['rating' => 2, 'count' => 0],
                ['rating' => 3, 'count' => 0],
                ['rating' => 4, 'count' => 1],
                ['rating' => 5, 'count' => 1],
            ]);
    }

    #[Test]
    public function active_member_can_filter_and_paginate_reviews(): void
    {
        $reviewed = $this->profile();
        $start = Carbon::parse('2030-01-01T10:00:00+00:00');

        for ($index = 0; $index < 7; $index++) {
            $this->createReview(
                $this->profile(),
                $reviewed,
                4,
                'Visible',
                $start->copy()->addMinutes($index)
            );
        }

        $this->createReview(
            $this->profile(),
            $reviewed,
            5,
            'Visible',
            $start->copy()->addMinutes(7)
        );

        Sanctum::actingAs(User::factory()->create());

        $this->getJson($this->url($reviewed).'?rating=4&sort=oldest&page=2')
            ->assertOk()
            ->assertJsonPath('data.average_rating', 4.1)
            ->assertJsonPath('data.reviews_count', 8)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.comment', 'Avaliação 4')
            ->assertJsonPath('data.items.1.comment', 'Avaliação 4')
            ->assertJsonPath('data.pagination', [
                'current_page' => 2,
                'per_page' => 5,
                'total' => 7,
                'last_page' => 2,
            ]);
    }

    #[Test]
    public function invalid_rating_and_sort_are_rejected(): void
    {
        $reviewed = $this->profile();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson($this->url($reviewed).'?rating=6')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rating']);

        $this->getJson($this->url($reviewed).'?sort=random')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort']);
    }

    #[Test]
    public function active_member_sees_reviewer_profile_id_and_business_name(): void
    {
        $reviewed = $this->profile();
        $reviewer = $this->profile();

        $this->createReview($reviewer, $reviewed, 5);

        $viewer = User::query()->findOrFail($reviewer->user_id);
        Sanctum::actingAs($viewer);

        $this->getJson($this->url($reviewed))
            ->assertOk()
            ->assertJsonPath('data.items.0.reviewer.id', $reviewer->id)
            ->assertJsonPath(
                'data.items.0.reviewer.business_name',
                $reviewer->business_name
            );
    }

    #[Test]
    public function soft_deleted_reviewer_is_returned_as_anonymized(): void
    {
        $reviewed = $this->profile();
        $reviewer = $this->profile();

        $this->createReview($reviewer, $reviewed, 5);

        $reviewer->delete();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson($this->url($reviewed))
            ->assertOk()
            ->assertJsonPath('data.items.0.reviewer.id', null)
            ->assertJsonPath(
                'data.items.0.reviewer.business_name',
                'Empresa anonimizada'
            );
    }

    #[Test]
    public function active_member_sees_their_own_review_first(): void
    {
        $ownProfile = $this->profile();
        $viewer = User::query()->findOrFail($ownProfile->user_id);

        $this->assertOwnReviewIsFirst($viewer, $ownProfile);
    }

    #[Test]
    public function active_admin_with_profile_sees_their_own_review_first(): void
    {
        $admin = User::factory()->admin()->create([
            'account_status_id' => AccountStatus::query()
                ->where('name', 'Active')
                ->value('id'),
        ]);

        $adminProfile = MemberProfile::factory()->create([
            'user_id' => $admin->id,
        ]);

        $this->assertOwnReviewIsFirst($admin, $adminProfile);
    }

    #[Test]
    public function own_hidden_and_flagged_reviews_remain_hidden(): void
    {
        $ownProfile = $this->profile();
        $viewer = User::query()->findOrFail($ownProfile->user_id);

        foreach (['Hidden', 'Flagged'] as $statusName) {
            $reviewed = $this->profile();
            $otherReviewer = $this->profile();

            $this->createReview($ownProfile, $reviewed, 5, $statusName);
            $visibleReview = $this->createReview($otherReviewer, $reviewed, 4);

            Sanctum::actingAs($viewer);

            $this->getJson($this->url($reviewed))
                ->assertOk()
                ->assertJsonCount(1, 'data.items')
                ->assertJsonPath('data.items.0.id', $visibleReview->id)
                ->assertJsonPath('data.items.0.is_mine', false);
        }
    }

    #[Test]
    public function own_review_is_included_first_even_when_the_rating_filter_excludes_it(): void
    {
        $reviewed = $this->profile();
        $ownProfile = $this->profile();
        $otherReviewer = $this->profile();
        $excludedReviewer = $this->profile();

        $ownReview = $this->createReview($ownProfile, $reviewed, 3);
        $matchingReview = $this->createReview($otherReviewer, $reviewed, 5);
        $excludedReview = $this->createReview($excludedReviewer, $reviewed, 4);

        $viewer = User::query()->findOrFail($ownProfile->user_id);
        Sanctum::actingAs($viewer);

        $this->getJson($this->url($reviewed).'?rating=5&sort=highest')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $ownReview->id)
            ->assertJsonPath('data.items.0.rating', 3)
            ->assertJsonPath('data.items.0.is_mine', true)
            ->assertJsonPath('data.items.1.id', $matchingReview->id)
            ->assertJsonPath('data.items.1.rating', 5)
            ->assertJsonPath('data.items.1.is_mine', false)
            ->assertJsonMissingPath('data.items.2')
            ->assertJsonPath('data.reviews_count', 3)
            ->assertJsonPath('data.pagination.total', 2);

        $this->assertDatabaseHas('reviews', [
            'id' => $excludedReview->id,
        ]);
    }
}
