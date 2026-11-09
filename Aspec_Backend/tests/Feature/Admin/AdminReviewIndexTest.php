<?php

namespace Tests\Feature\Admin;

use App\Models\MemberProfile;
use App\Models\Review;
use App\Models\ReviewReport;
use App\Models\ReviewStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function reportWith(Review $review, array $attributes, string $createdAt = '2026-11-01 10:00:00'): ReviewReport
    {
        $report = ReviewReport::query()->create([
            'review_id' => $review->id,
            'reporter_profile_id' => null,
            'reason' => ReviewReport::REASON_OTHER,
            'comment' => null,
            'source' => ReviewReport::SOURCE_MEMBER,
            ...$attributes,
        ]);

        $report->forceFill(['created_at' => $createdAt])->save();

        return $report;
    }

    private function countQueriesOfTheList(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson(self::URL.'?status=all&per_page=50')->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    #[Test]
    public function list_includes_only_unresolved_reports_in_creation_order_with_the_full_comment(): void
    {
        $this->actingAsAdmin();
        $review = $this->createReview('Flagged');
        $longComment = str_repeat('Comentário detalhado do report. ', 40);

        $newer = $this->reportWith($review, [
            'reason' => 'language_filter',
            'source' => ReviewReport::SOURCE_AUTOMATIC,
        ], '2026-11-02 10:00:00');
        $older = $this->reportWith($review, [
            'reason' => ReviewReport::REASON_SPAM_OR_ADVERTISING,
            'comment' => $longComment,
            'reporter_profile_id' => $this->profile()->id,
        ], '2026-11-01 10:00:00');
        $this->reportWith($review, [
            'comment' => 'Report já resolvido.',
            'resolved_at' => now(),
        ]);

        $response = $this->getJson(self::URL)->assertOk();
        $reports = $response->json('data.items.0.reports');

        $this->assertSame([$older->id, $newer->id], array_column($reports, 'id'));
        $this->assertEqualsCanonicalizing(['id', 'source', 'reason', 'comment', 'created_at'], array_keys($reports[0]));
        $this->assertSame(ReviewReport::SOURCE_MEMBER, $reports[0]['source']);
        $this->assertSame(ReviewReport::REASON_SPAM_OR_ADVERTISING, $reports[0]['reason']);
        $this->assertSame($longComment, $reports[0]['comment']);
        $this->assertSame('language_filter', $reports[1]['reason']);
        $this->assertNull($reports[1]['comment']);
        $response->assertDontSee('Report já resolvido.');
    }

    #[Test]
    public function reports_never_identify_who_reported(): void
    {
        $this->actingAsAdmin();
        $review = $this->createReview('Flagged');
        $reporter = MemberProfile::factory()->create([
            'user_id' => User::factory()->create()->id,
            'business_name' => 'Empresa Denunciante Lda',
        ]);
        $this->reportWith($review, ['reporter_profile_id' => $reporter->id, 'comment' => 'Detalhe.']);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(1, 'data.items.0.reports')
            ->assertJsonMissingPath('data.items.0.reports.0.reporter_profile_id')
            ->assertJsonMissingPath('data.items.0.reports.0.reporter')
            ->assertDontSee('Empresa Denunciante Lda')
            ->assertDontSee($reporter->id);
    }

    /**
     * A origem continua a contar reports resolvidos (contrato já existente), ao contrário de reports.
     */
    #[Test]
    public function flagging_sources_still_counts_resolved_reports_while_reports_does_not(): void
    {
        $this->actingAsAdmin();
        $review = $this->createReview('Visible');
        $this->createReport($review, ReviewReport::SOURCE_MEMBER, now()->toDateTimeString());

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.items.0.flagging_sources', ['member'])
            ->assertJsonPath('data.items.0.reports', []);
    }

    /**
     * O primeiro pedido carrega o papel e o estado do admin autenticado e o resultado fica em memória;
     * esse pedido inicial fica de fora para as duas contagens serem comparáveis.
     */
    #[Test]
    public function number_of_queries_does_not_grow_with_the_number_of_reviews(): void
    {
        $this->actingAsAdmin();
        $this->createReport($this->createReview('Flagged'), ReviewReport::SOURCE_MEMBER);

        $this->countQueriesOfTheList();
        $withOneReview = $this->countQueriesOfTheList();

        for ($index = 0; $index < 4; $index++) {
            $this->createReport($this->createReview('Flagged'), ReviewReport::SOURCE_MEMBER);
        }

        $this->assertSame($withOneReview, $this->countQueriesOfTheList());
    }
}
