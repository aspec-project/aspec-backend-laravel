<?php

namespace Tests\Feature\Member;

use App\Models\MemberProfile;
use App\Models\Review;
use App\Models\ReviewReport;
use App\Models\ReviewStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MyReviewsIndexTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/me/reviews';

    private function profile(): MemberProfile
    {
        return MemberProfile::factory()->create([
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function createReview(
        MemberProfile $reviewer,
        MemberProfile $reviewed,
        string $status = 'Visible',
        ?string $hiddenReason = null
    ): Review {
        return Review::query()->create([
            'reviewer_profile_id' => $reviewer->id,
            'reviewed_profile_id' => $reviewed->id,
            'rating' => 4,
            'comment' => 'Comentário de teste.',
            'status_id' => ReviewStatus::query()
                ->where('name', $status)
                ->valueOrFail('id'),
            'hidden_reason' => $hiddenReason,
        ]);
    }

    #[Test]
    public function member_sees_reviews_received_and_written_by_default(): void
    {
        $myProfile = $this->profile();
        $reviewer = $this->profile();
        $reviewed = $this->profile();
        $unrelatedReviewer = $this->profile();
        $unrelatedReviewed = $this->profile();

        $receivedReview = $this->createReview(
            $reviewer,
            $myProfile,
            'Hidden',
            'A avaliação foi ocultada por conter informação inadequada.'
        );

        $writtenReview = $this->createReview(
            $myProfile,
            $reviewed,
            'Visible'
        );

        $unrelatedReview = $this->createReview(
            $unrelatedReviewer,
            $unrelatedReviewed,
            'Visible'
        );

        Sanctum::actingAs(User::query()->findOrFail($myProfile->user_id));

        $response = $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pagination.total', 2);

        $itemsById = collect($response->json('data.items'))->keyBy('id');

        $this->assertSame(
            'received',
            $itemsById->get($receivedReview->id)['direction']
        );

        $this->assertSame(
            'A avaliação foi ocultada por conter informação inadequada.',
            $itemsById->get($receivedReview->id)['hidden_reason']
        );

        $this->assertSame(
            'written',
            $itemsById->get($writtenReview->id)['direction']
        );

        $this->assertNull(
            $itemsById->get($writtenReview->id)['hidden_reason']
        );

        $this->assertArrayNotHasKey($unrelatedReview->id, $itemsById->all());
    }

    #[Test]
    public function member_can_filter_by_direction_and_status(): void
    {
        $myProfile = $this->profile();
        $receivedReviewer = $this->profile();
        $writtenRecipient = $this->profile();

        $hiddenReceived = $this->createReview(
            $receivedReviewer,
            $myProfile,
            'Hidden',
            'Justificação da ocultação.'
        );

        $flaggedWritten = $this->createReview(
            $myProfile,
            $writtenRecipient,
            'Flagged'
        );

        Sanctum::actingAs(User::query()->findOrFail($myProfile->user_id));

        $this->getJson(self::URL.'?direction=received&status=hidden')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $hiddenReceived->id)
            ->assertJsonPath('data.items.0.direction', 'received')
            ->assertJsonPath(
                'data.items.0.hidden_reason',
                'Justificação da ocultação.'
            );

        $this->getJson(self::URL.'?direction=written&status=flagged')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $flaggedWritten->id)
            ->assertJsonPath('data.items.0.direction', 'written')
            ->assertJsonPath('data.items.0.hidden_reason', null);
    }

    #[Test]
    public function member_can_filter_all_reviews_by_status_and_paginate(): void
    {
        $myProfile = $this->profile();

        for ($index = 0; $index < 3; $index++) {
            $this->createReview(
                $this->profile(),
                $myProfile,
                'Visible'
            );
        }

        Sanctum::actingAs(User::query()->findOrFail($myProfile->user_id));

        $this->getJson(self::URL.'?direction=received&status=visible&per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.pagination', [
                'current_page' => 2,
                'per_page' => 2,
                'total' => 3,
                'last_page' => 2,
            ]);
    }

    #[Test]
    public function invalid_direction_status_and_pagination_filters_are_rejected(): void
    {
        $myProfile = $this->profile();

        Sanctum::actingAs(User::query()->findOrFail($myProfile->user_id));

        $this->getJson(self::URL.'?direction=sent')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['direction']);

        $this->getJson(self::URL.'?status=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->getJson(self::URL.'?per_page=51')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    #[Test]
    public function guest_and_active_user_without_a_member_profile_cannot_list_my_reviews(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson(self::URL)
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'Perfil de membro não encontrado.'
            );
    }

    private function createReport(
        Review $review,
        string $reason,
        ?MemberProfile $reporter = null,
        ?string $comment = null,
        ?string $resolvedAt = null
    ): ReviewReport {
        return ReviewReport::query()->create([
            'review_id' => $review->id,
            'reporter_profile_id' => $reporter?->id,
            'reason' => $reason,
            'comment' => $comment,
            'source' => $reason === 'language_filter'
                ? ReviewReport::SOURCE_AUTOMATIC
                : ReviewReport::SOURCE_MEMBER,
            'resolved_at' => $resolvedAt,
        ]);
    }

    #[Test]
    public function counts_are_the_totals_of_each_tab_whatever_the_filters_and_page(): void
    {
        $myProfile = $this->profile();

        $this->createReview($this->profile(), $myProfile, 'Visible');
        $this->createReview($this->profile(), $myProfile, 'Visible');
        $this->createReview($this->profile(), $myProfile, 'Hidden');
        $this->createReview($myProfile, $this->profile(), 'Flagged');
        $this->createReview($myProfile, $this->profile(), 'Visible');
        $this->createReview($this->profile(), $this->profile(), 'Visible');

        Sanctum::actingAs(User::query()->findOrFail($myProfile->user_id));

        foreach ([
            '',
            '?direction=received',
            '?direction=written',
            '?status=hidden',
            '?direction=written&status=flagged',
            '?per_page=1&page=2',
        ] as $query) {
            $this->getJson(self::URL.$query)
                ->assertOk()
                ->assertJsonPath('data.counts', ['received' => 3, 'written' => 2]);
        }
    }

    #[Test]
    public function counts_are_zero_for_a_member_without_reviews(): void
    {
        Sanctum::actingAs(User::query()->findOrFail($this->profile()->user_id));

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.counts', ['received' => 0, 'written' => 0]);
    }

    #[Test]
    public function flagging_reasons_are_unique_sorted_and_ignore_resolved_reports(): void
    {
        $myProfile = $this->profile();
        $review = $this->createReview($this->profile(), $myProfile, 'Flagged');

        $this->createReport($review, ReviewReport::REASON_SPAM_OR_ADVERTISING);
        $this->createReport($review, ReviewReport::REASON_OTHER);
        $this->createReport($review, ReviewReport::REASON_SPAM_OR_ADVERTISING);
        $this->createReport($review, ReviewReport::REASON_FALSE_OR_UNSUBSTANTIATED_CLAIMS, resolvedAt: now()->toDateTimeString());

        Sanctum::actingAs(User::query()->findOrFail($myProfile->user_id));

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.items.0.flagging_reasons', [
                ReviewReport::REASON_OTHER,
                ReviewReport::REASON_SPAM_OR_ADVERTISING,
            ]);
    }

    #[Test]
    public function automatic_flagging_exposes_the_language_filter_code(): void
    {
        $myProfile = $this->profile();
        $this->createReport(
            $this->createReview($myProfile, $this->profile(), 'Flagged'),
            'language_filter'
        );

        Sanctum::actingAs(User::query()->findOrFail($myProfile->user_id));

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.items.0.flagging_reasons', ['language_filter']);
    }

    #[Test]
    public function flagging_reasons_are_empty_unless_the_review_is_flagged(): void
    {
        $myProfile = $this->profile();
        $visible = $this->createReview($this->profile(), $myProfile, 'Visible');
        $hidden = $this->createReview($myProfile, $this->profile(), 'Hidden', 'Justificação da administração.');

        $this->createReport($visible, ReviewReport::REASON_OTHER);
        $this->createReport($hidden, ReviewReport::REASON_OTHER);

        Sanctum::actingAs(User::query()->findOrFail($myProfile->user_id));

        $itemsById = collect($this->getJson(self::URL)->assertOk()->json('data.items'))->keyBy('id');

        $this->assertSame([], $itemsById->get($visible->id)['flagging_reasons']);
        $this->assertSame([], $itemsById->get($hidden->id)['flagging_reasons']);
        $this->assertSame('Justificação da administração.', $itemsById->get($hidden->id)['hidden_reason']);
    }

    /**
     * O autor da avaliação vê porquê foi sinalizada, mas nunca o que outro membro escreveu
     * no report nem quem o fez.
     */
    #[Test]
    public function both_tabs_show_reason_codes_but_never_the_report_comment_or_the_reporter(): void
    {
        $myProfile = $this->profile();
        $reporter = MemberProfile::factory()->create([
            'user_id' => User::factory()->create()->id,
            'business_name' => 'Empresa Denunciante Lda',
        ]);

        foreach ([
            $this->createReview($this->profile(), $myProfile, 'Flagged'),
            $this->createReview($myProfile, $this->profile(), 'Flagged'),
        ] as $review) {
            $this->createReport($review, ReviewReport::REASON_NO_BUSINESS_RELATIONSHIP, $reporter, 'Texto privado do report.');
        }

        Sanctum::actingAs(User::query()->findOrFail($myProfile->user_id));

        foreach (['received', 'written'] as $direction) {
            $this->getJson(self::URL.'?direction='.$direction)
                ->assertOk()
                ->assertJsonPath('data.items.0.flagging_reasons', [ReviewReport::REASON_NO_BUSINESS_RELATIONSHIP])
                ->assertJsonMissingPath('data.items.0.reports')
                ->assertDontSee('Texto privado do report.')
                ->assertDontSee('Empresa Denunciante Lda')
                ->assertDontSee($reporter->id);
        }
    }
}
