<?php

namespace Tests\Feature\Member;

use App\Models\MemberProfile;
use App\Models\Review;
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
}
