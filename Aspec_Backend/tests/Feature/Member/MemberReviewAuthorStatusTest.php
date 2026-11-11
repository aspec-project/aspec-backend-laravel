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
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberReviewAuthorStatusTest extends TestCase
{
    use RefreshDatabase;

    private const MODERATION_MESSAGE = 'A sua avaliação ficou a aguardar moderação.';

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

    private function configureModerationTerms(): void
    {
        Config::set('review_moderation.terms', [
            'pt-PT' => [
                'words' => ['moderationtestterm'],
                'phrases' => ['moderation test phrase'],
            ],
        ]);
    }

    private function existingReview(
        MemberProfile $reviewerProfile,
        MemberProfile $reviewedProfile,
        string $status,
        array $extra = [],
    ): Review {
        return Review::query()->create([
            'reviewer_profile_id' => $reviewerProfile->id,
            'reviewed_profile_id' => $reviewedProfile->id,
            'rating' => 2,
            'comment' => 'Comentário original.',
            'status_id' => $this->statusId($status),
            ...$extra,
        ]);
    }

    private function statusId(string $status): string
    {
        return ReviewStatus::query()->where('name', $status)->value('id');
    }

    #[Test]
    public function new_clean_review_returns_visible_status_to_the_author(): void
    {
        $this->configureModerationTerms();

        [$reviewer] = $this->memberWithProfile();
        [, $reviewedProfile] = $this->memberWithProfile();

        Sanctum::actingAs($reviewer);

        $this->postJson($this->url($reviewedProfile), [
            'rating' => 5,
            'comment' => 'Excelente serviço e comunicação.',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Avaliação criada com sucesso.')
            ->assertJsonPath('data.status', 'Visible');
    }

    #[Test]
    public function new_review_caught_by_the_filter_returns_flagged_and_awaiting_moderation_message(): void
    {
        $this->configureModerationTerms();

        [$reviewer, $reviewerProfile] = $this->memberWithProfile();
        [, $reviewedProfile] = $this->memberWithProfile();

        Sanctum::actingAs($reviewer);

        $comment = 'Comentário com moderationtestterm.';

        $this->postJson($this->url($reviewedProfile), [
            'rating' => 4,
            'comment' => $comment,
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', self::MODERATION_MESSAGE)
            ->assertJsonPath('data.status', 'Flagged')
            ->assertJsonPath('data.comment', $comment)
            ->assertJsonPath('data.is_mine', true);

        $review = Review::query()
            ->where('reviewer_profile_id', $reviewerProfile->id)
            ->where('reviewed_profile_id', $reviewedProfile->id)
            ->firstOrFail();

        $this->assertSame($this->statusId('Flagged'), $review->status_id);

        $this->assertDatabaseHas('review_reports', [
            'review_id' => $review->id,
            'reason' => 'language_filter',
            'source' => ReviewReport::SOURCE_AUTOMATIC,
            'resolved_at' => null,
        ]);

        $this->assertDatabaseCount('review_reports', 1);
    }

    #[Test]
    public function updating_a_visible_review_with_clean_text_returns_visible_and_updated_message(): void
    {
        $this->configureModerationTerms();

        [$reviewer, $reviewerProfile] = $this->memberWithProfile();
        [, $reviewedProfile] = $this->memberWithProfile();

        $this->existingReview($reviewerProfile, $reviewedProfile, 'Visible');

        Sanctum::actingAs($reviewer);

        $this->postJson($this->url($reviewedProfile), [
            'rating' => 4,
            'comment' => 'Comentário atualizado.',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Avaliação atualizada com sucesso.')
            ->assertJsonPath('data.status', 'Visible');
    }

    #[Test]
    public function updating_a_visible_review_caught_by_the_filter_returns_flagged_and_awaiting_moderation_message(): void
    {
        $this->configureModerationTerms();

        [$reviewer, $reviewerProfile] = $this->memberWithProfile();
        [, $reviewedProfile] = $this->memberWithProfile();

        $this->existingReview($reviewerProfile, $reviewedProfile, 'Visible');

        Sanctum::actingAs($reviewer);

        $this->postJson($this->url($reviewedProfile), [
            'rating' => 4,
            'comment' => 'Comentário com moderationtestterm.',
        ])
            ->assertOk()
            ->assertJsonPath('message', self::MODERATION_MESSAGE)
            ->assertJsonPath('data.status', 'Flagged');
    }

    #[Test]
    public function updating_an_already_flagged_review_with_clean_text_keeps_flagged_and_awaiting_moderation_message(): void
    {
        $this->configureModerationTerms();

        [$reviewer, $reviewerProfile] = $this->memberWithProfile();
        [, $reviewedProfile] = $this->memberWithProfile();
        [, $reporterProfile] = $this->memberWithProfile();

        $review = $this->existingReview($reviewerProfile, $reviewedProfile, 'Flagged');

        $review->reports()->create([
            'reporter_profile_id' => $reporterProfile->id,
            'reason' => ReviewReport::REASON_OTHER,
            'comment' => null,
            'source' => ReviewReport::SOURCE_MEMBER,
        ]);

        Sanctum::actingAs($reviewer);

        $this->postJson($this->url($reviewedProfile), [
            'rating' => 4,
            'comment' => 'Comentário atualizado.',
        ])
            ->assertOk()
            ->assertJsonPath('message', self::MODERATION_MESSAGE)
            ->assertJsonPath('data.status', 'Flagged');

        $this->assertSame($this->statusId('Flagged'), $review->fresh()->status_id);
        $this->assertDatabaseCount('review_reports', 1);
    }

    #[Test]
    public function updating_a_hidden_review_keeps_the_current_message_and_does_not_return_the_justification(): void
    {
        $this->configureModerationTerms();

        [$reviewer, $reviewerProfile] = $this->memberWithProfile();
        [, $reviewedProfile] = $this->memberWithProfile();

        $review = $this->existingReview($reviewerProfile, $reviewedProfile, 'Hidden', [
            'hidden_reason' => 'Linguagem imprópria.',
        ]);

        Sanctum::actingAs($reviewer);

        $this->postJson($this->url($reviewedProfile), [
            'rating' => 4,
            'comment' => 'Comentário atualizado.',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Avaliação atualizada com sucesso.')
            ->assertJsonPath('data.status', 'Hidden')
            ->assertJsonMissingPath('data.hidden_reason');

        $this->assertSame($this->statusId('Hidden'), $review->fresh()->status_id);
    }

    #[Test]
    public function author_response_has_exactly_the_public_fields_plus_status(): void
    {
        $this->configureModerationTerms();

        [$reviewer] = $this->memberWithProfile();
        [, $reviewedProfile] = $this->memberWithProfile();

        Sanctum::actingAs($reviewer);

        $this->postJson($this->url($reviewedProfile), [
            'rating' => 5,
            'comment' => 'Excelente serviço e comunicação.',
        ])
            ->assertCreated()
            ->assertExactJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'rating',
                    'comment',
                    'created_at',
                    'updated_at',
                    'reviewer' => ['id', 'business_name'],
                    'is_mine',
                    'status',
                ],
            ]);
    }

    #[Test]
    public function public_review_list_still_has_no_status(): void
    {
        [$author, $authorProfile] = $this->memberWithProfile();
        [, $reviewedProfile] = $this->memberWithProfile();
        [$otherMember] = $this->memberWithProfile();

        $this->existingReview($authorProfile, $reviewedProfile, 'Visible');

        Sanctum::actingAs($otherMember);

        $this->getJson($this->url($reviewedProfile))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonMissingPath('data.items.0.status');

        Sanctum::actingAs($author);

        $this->getJson($this->url($reviewedProfile))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonMissingPath('data.items.0.status');
    }
}
