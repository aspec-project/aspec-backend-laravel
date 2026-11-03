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

class AdminReviewModerationTest extends TestCase
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
        ?string $hiddenReason = null
    ): Review {
        return Review::query()->create([
            'reviewer_profile_id' => $this->profile()->id,
            'reviewed_profile_id' => $this->profile()->id,
            'rating' => 4,
            'comment' => 'Comentário de teste.',
            'status_id' => ReviewStatus::query()
                ->where('name', $status)
                ->valueOrFail('id'),
            'hidden_reason' => $hiddenReason,
        ]);
    }

    private function createReport(
        Review $review,
        string $source = ReviewReport::SOURCE_MEMBER
    ): ReviewReport {
        return ReviewReport::query()->create([
            'review_id' => $review->id,
            'reporter_profile_id' => $source === ReviewReport::SOURCE_MEMBER
                ? $this->profile()->id
                : null,
            'reason' => $source === ReviewReport::SOURCE_AUTOMATIC
                ? 'language_filter'
                : 'member_report',
            'comment' => 'Detalhe do report.',
            'source' => $source,
        ]);
    }

    private function actionUrl(Review $review, string $action): string
    {
        return self::URL."/{$review->id}/{$action}";
    }

    #[Test]
    public function admin_can_hide_a_visible_review_and_resolve_open_reports(): void
    {
        $this->actingAsAdmin();

        $review = $this->createReview('Visible');
        $report = $this->createReport($review);

        $this->patchJson($this->actionUrl($review, 'hide'), [
            'justification' => 'A avaliação contém informação inadequada.',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'Hidden')
            ->assertJsonPath(
                'data.hidden_reason',
                'A avaliação contém informação inadequada.'
            );

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'status_id' => ReviewStatus::query()
                ->where('name', 'Hidden')
                ->valueOrFail('id'),
            'hidden_reason' => 'A avaliação contém informação inadequada.',
        ]);

        $this->assertNotNull($report->fresh()->resolved_at);
    }

    #[Test]
    public function admin_can_hide_a_flagged_review_without_a_justification(): void
    {
        $this->actingAsAdmin();

        $review = $this->createReview('Flagged');
        $report = $this->createReport(
            $review,
            ReviewReport::SOURCE_AUTOMATIC
        );

        $this->patchJson($this->actionUrl($review, 'hide'))
            ->assertOk()
            ->assertJsonPath('data.status', 'Hidden')
            ->assertJsonPath('data.hidden_reason', null);

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'status_id' => ReviewStatus::query()
                ->where('name', 'Hidden')
                ->valueOrFail('id'),
            'hidden_reason' => null,
        ]);

        $this->assertNotNull($report->fresh()->resolved_at);
    }

    #[Test]
    public function admin_can_show_a_hidden_review_and_clear_its_justification(): void
    {
        $this->actingAsAdmin();

        $review = $this->createReview(
            'Hidden',
            'Justificação anterior.'
        );
        $report = $this->createReport($review);

        $this->patchJson($this->actionUrl($review, 'show'))
            ->assertOk()
            ->assertJsonPath('data.status', 'Visible')
            ->assertJsonPath('data.hidden_reason', null);

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'status_id' => ReviewStatus::query()
                ->where('name', 'Visible')
                ->valueOrFail('id'),
            'hidden_reason' => null,
        ]);

        $this->assertNotNull($report->fresh()->resolved_at);
    }

    #[Test]
    public function admin_can_remove_a_flag_and_resolve_open_reports(): void
    {
        $this->actingAsAdmin();

        $review = $this->createReview('Flagged');
        $report = $this->createReport(
            $review,
            ReviewReport::SOURCE_AUTOMATIC
        );

        $this->patchJson($this->actionUrl($review, 'unflag'))
            ->assertOk()
            ->assertJsonPath('data.status', 'Visible')
            ->assertJsonPath('data.hidden_reason', null);

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'status_id' => ReviewStatus::query()
                ->where('name', 'Visible')
                ->valueOrFail('id'),
            'hidden_reason' => null,
        ]);

        $this->assertNotNull($report->fresh()->resolved_at);
    }

    #[Test]
    public function moderation_actions_return_conflict_for_incompatible_statuses(): void
    {
        $this->actingAsAdmin();

        $hidden = $this->createReview('Hidden', 'Manter esta justificação.');
        $visible = $this->createReview('Visible');

        $this->patchJson($this->actionUrl($hidden, 'hide'))
            ->assertStatus(409);

        $this->patchJson($this->actionUrl($visible, 'show'))
            ->assertStatus(409);

        $this->patchJson($this->actionUrl($visible, 'unflag'))
            ->assertStatus(409);

        $this->assertDatabaseHas('reviews', [
            'id' => $hidden->id,
            'status_id' => ReviewStatus::query()
                ->where('name', 'Hidden')
                ->valueOrFail('id'),
            'hidden_reason' => 'Manter esta justificação.',
        ]);

        $this->assertDatabaseHas('reviews', [
            'id' => $visible->id,
            'status_id' => ReviewStatus::query()
                ->where('name', 'Visible')
                ->valueOrFail('id'),
        ]);
    }

    #[Test]
    public function hide_rejects_a_justification_longer_than_2000_characters(): void
    {
        $this->actingAsAdmin();

        $review = $this->createReview('Visible');

        $this->patchJson($this->actionUrl($review, 'hide'), [
            'justification' => str_repeat('x', 2001),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['justification']);

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'status_id' => ReviewStatus::query()
                ->where('name', 'Visible')
                ->valueOrFail('id'),
            'hidden_reason' => null,
        ]);
    }

    #[Test]
    public function only_an_admin_can_moderate_reviews(): void
    {
        $review = $this->createReview('Visible');

        foreach (['hide', 'show', 'unflag'] as $action) {
            $this->patchJson($this->actionUrl($review, $action))
                ->assertUnauthorized();
        }

        Sanctum::actingAs(User::factory()->create());

        foreach (['hide', 'show', 'unflag'] as $action) {
            $this->patchJson($this->actionUrl($review, $action))
                ->assertForbidden();
        }
    }
}
