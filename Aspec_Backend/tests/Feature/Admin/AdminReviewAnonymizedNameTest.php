<?php

namespace Tests\Feature\Admin;

use App\Models\MemberProfile;
use App\Models\Review;
use App\Models\ReviewStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminReviewAnonymizedNameTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/reviews';

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->technicalAdmin()->create());
    }

    private function profile(string $businessName): MemberProfile
    {
        return MemberProfile::factory()->create([
            'user_id' => User::factory()->create()->id,
            'business_name' => $businessName,
        ]);
    }

    private function createReview(
        MemberProfile $reviewer,
        MemberProfile $reviewed,
        string $status
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

    #[Test]
    public function admin_review_list_shows_anonymized_business_name_for_an_anonymized_reviewer(): void
    {
        $ana = $this->profile('Ana Lda.');
        $bruno = $this->profile('Bruno Construções');
        $review = $this->createReview($bruno, $ana, 'Flagged');
        $bruno->user->anonymizeAndDelete();

        $this->actingAsAdmin();

        $response = $this->getJson(self::URL.'?status=all')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertDontSee('Empresa Removida');

        $item = collect($response->json('data.items'))->firstWhere('id', $review->id);

        $this->assertNotNull($item);
        $this->assertSame('Empresa anonimizada', $item['reviewer']['business_name']);
        $this->assertSame('Ana Lda.', $item['reviewed']['business_name']);
    }

    #[Test]
    public function admin_review_list_shows_anonymized_business_name_for_a_deleted_reviewed_profile(): void
    {
        $ana = $this->profile('Ana Lda.');
        $bruno = $this->profile('Bruno Construções');
        $review = $this->createReview($ana, $bruno, 'Visible');
        $bruno->delete();

        $this->actingAsAdmin();

        $response = $this->getJson(self::URL.'?status=all')
            ->assertOk();

        $this->assertStringNotContainsString(
            'Bruno Construções',
            json_encode($response->json(), JSON_UNESCAPED_UNICODE)
        );

        $item = collect($response->json('data.items'))->firstWhere('id', $review->id);

        $this->assertNotNull($item);
        $this->assertSame('Empresa anonimizada', $item['reviewed']['business_name']);
        $this->assertSame('Ana Lda.', $item['reviewer']['business_name']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function moderationActions(): array
    {
        return [
            'retirar sinalização' => ['unflag', 'Flagged'],
            'ocultar' => ['hide', 'Visible'],
        ];
    }

    #[Test]
    #[DataProvider('moderationActions')]
    public function moderation_action_response_shows_anonymized_business_name(
        string $action,
        string $status
    ): void {
        $ana = $this->profile('Ana Lda.');
        $bruno = $this->profile('Bruno Construções');
        $review = $this->createReview($bruno, $ana, $status);
        $bruno->user->anonymizeAndDelete();

        $this->actingAsAdmin();

        $this->patchJson(self::URL."/{$review->id}/{$action}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.reviewer.business_name', 'Empresa anonimizada')
            ->assertJsonPath('data.reviewed.business_name', 'Ana Lda.');
    }
}
