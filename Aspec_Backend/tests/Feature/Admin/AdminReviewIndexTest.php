<?php

namespace Tests\Feature\Admin;

use App\Models\MemberProfile;
use App\Models\Review;
use App\Models\ReviewReport;
use App\Models\ReviewStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminReviewIndexTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/reviews';

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->technicalAdmin()->create());
    }

    private function profile(): MemberProfile
    {
        return MemberProfile::factory()->create([
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function createReview(
        string $status,
        string $comment = 'Comentário de teste.'
    ): Review {
        return Review::query()->create([
            'reviewer_profile_id' => $this->profile()->id,
            'reviewed_profile_id' => $this->profile()->id,
            'rating' => 4,
            'comment' => $comment,
            'status_id' => ReviewStatus::query()
                ->where('name', $status)
                ->valueOrFail('id'),
        ]);
    }

    private function createReport(
        Review $review,
        string $source,
        ?string $resolvedAt = null
    ): ReviewReport {
        return ReviewReport::query()->create([
            'review_id' => $review->id,
            'reporter_profile_id' => $source === ReviewReport::SOURCE_MEMBER
                ? $this->profile()->id
                : null,
            'reason' => $source === ReviewReport::SOURCE_AUTOMATIC
                ? 'language_filter'
                : 'member_report',
            'comment' => 'Detalhe do report de teste.',
            'source' => $source,
            'resolved_at' => $resolvedAt,
        ]);
    }

    #[Test]
    public function admin_can_filter_reviews_and_sees_global_counts_and_flagging_sources(): void
    {
        $this->actingAsAdmin();

        $flagged = $this->createReview('Flagged', 'Comentário sinalizado.');
        $visible = $this->createReview('Visible', 'Comentário visível.');
        $hidden = $this->createReview('Hidden', 'Comentário oculto.');

        $this->createReport(
            $flagged,
            ReviewReport::SOURCE_AUTOMATIC
        );

        $this->createReport(
            $visible,
            ReviewReport::SOURCE_MEMBER,
            now()->toDateTimeString()
        );

        $allResponse = $this->getJson(self::URL.'?status=all')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.counts', [
                'all' => 3,
                'flagged' => 1,
                'visible' => 1,
                'hidden' => 1,
            ])
            ->assertJsonPath('data.pagination.total', 3);

        $itemsById = collect($allResponse->json('data.items'))
            ->keyBy('id');

        $this->assertSame(
            ['automatic'],
            $itemsById->get($flagged->id)['flagging_sources']
        );

        $this->assertSame(
            ['member'],
            $itemsById->get($visible->id)['flagging_sources']
        );

        $this->assertSame(
            [],
            $itemsById->get($hidden->id)['flagging_sources']
        );

        foreach ([
            'flagged' => $flagged,
            'visible' => $visible,
            'hidden' => $hidden,
        ] as $status => $expectedReview) {
            $this->getJson(self::URL.'?status='.$status)
                ->assertOk()
                ->assertJsonCount(1, 'data.items')
                ->assertJsonPath('data.items.0.id', $expectedReview->id)
                ->assertJsonPath('data.counts.all', 3)
                ->assertJsonPath('data.counts.flagged', 1)
                ->assertJsonPath('data.counts.visible', 1)
                ->assertJsonPath('data.counts.hidden', 1);
        }
    }

    #[Test]
    public function admin_review_list_is_paginated(): void
    {
        $this->actingAsAdmin();

        for ($index = 0; $index < 12; $index++) {
            $this->createReview('Visible', "Comentário {$index}.");
        }

        $this->getJson(self::URL.'?status=visible&per_page=5&page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data.items')
            ->assertJsonPath('data.pagination', [
                'current_page' => 2,
                'per_page' => 5,
                'total' => 12,
                'last_page' => 3,
            ])
            ->assertJsonPath('data.counts.all', 12)
            ->assertJsonPath('data.counts.visible', 12);
    }

    #[Test]
    public function invalid_status_and_per_page_filters_are_rejected(): void
    {
        $this->actingAsAdmin();

        $this->getJson(self::URL.'?status=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->getJson(self::URL.'?per_page=51')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    #[Test]
    public function only_an_admin_can_list_reviews(): void
    {
        $this->getJson(self::URL)
            ->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson(self::URL)
            ->assertForbidden();
    }
}
