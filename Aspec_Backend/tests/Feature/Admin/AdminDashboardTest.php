<?php

namespace Tests\Feature\Admin;

use App\Enums\InactiveReason;
use App\Models\EventInstance;
use App\Models\EventSeries;
use App\Models\MemberProfile;
use App\Models\Review;
use App\Models\ReviewStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/dashboard';

    private const SUCCESS_MESSAGE = 'Dados do painel obtidos com sucesso.';

    private const FORBIDDEN_MESSAGE = 'Não tem permissão para realizar esta ação.';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Notification::fake();
        Queue::fake();
    }

    private function actingAsTechnicalAdmin(): User
    {
        $admin = User::factory()->technicalAdmin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function nowInLisbon(string $dateTime): void
    {
        $this->travelTo(Carbon::parse($dateTime, 'Europe/Lisbon'));
    }

    private function withProfile(User $user, array $attributes = []): User
    {
        MemberProfile::factory()->create(array_merge(['user_id' => $user->id], $attributes));

        return $user->fresh();
    }

    private function inGrace(User $user): User
    {
        $user->forceFill(['grace_ends_at' => now()->addDays(3)])->save();

        return $user;
    }

    private function subscription(User $user, string $createdAtUtc): void
    {
        DB::table('subscriptions')->insert([
            'user_id' => $user->id,
            'type' => 'default',
            'stripe_id' => 'sub_test_'.Str::random(12),
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'created_at' => $createdAtUtc,
            'updated_at' => $createdAtUtc,
        ]);
    }

    private function event(string $date, array $attributes = []): EventInstance
    {
        return EventInstance::factory()->create(array_merge([
            'event_date' => $date,
            'registration_deadline' => Carbon::parse($date)->subDay()->format('Y-m-d').' 23:59:00',
        ], $attributes));
    }

    private function profile(?string $businessName = null): MemberProfile
    {
        $attributes = ['user_id' => User::factory()->create()->id];

        if ($businessName !== null) {
            $attributes['business_name'] = $businessName;
        }

        return MemberProfile::factory()->create($attributes);
    }

    private function review(
        MemberProfile $reviewer,
        MemberProfile $reviewed,
        string $status = 'Visible',
        ?string $createdAtUtc = null,
        ?string $updatedAtUtc = null,
        int $rating = 4,
        string $comment = 'Bom serviço.'
    ): Review {
        $review = Review::query()->create([
            'reviewer_profile_id' => $reviewer->id,
            'reviewed_profile_id' => $reviewed->id,
            'rating' => $rating,
            'comment' => $comment,
            'status_id' => ReviewStatus::query()->where('name', $status)->value('id'),
        ]);

        if ($createdAtUtc !== null) {
            DB::table('reviews')->where('id', $review->id)->update([
                'created_at' => $createdAtUtc,
                'updated_at' => $updatedAtUtc ?? $createdAtUtc,
            ]);
        }

        return $review;
    }

    private function dashboard(): TestResponse
    {
        return $this->getJson(self::URL)->assertOk();
    }

    private function assertJsonError(TestResponse $response, int $status, string $message): void
    {
        $response->assertStatus($status)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', $message);
    }

    #[Test]
    public function guest_cannot_see_the_dashboard(): void
    {
        $this->assertJsonError($this->getJson(self::URL), 401, 'Não autenticado.');
    }

    #[Test]
    public function members_and_inactive_admins_cannot_see_the_dashboard(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->assertJsonError($this->getJson(self::URL), 403, self::FORBIDDEN_MESSAGE);

        Sanctum::actingAs(User::factory()->admin()->inactive(InactiveReason::Blocked)->create());
        $this->getJson(self::URL)->assertForbidden();
    }

    #[Test]
    public function dashboard_with_only_seeds_returns_zeros_and_empty_lists(): void
    {
        $this->actingAsTechnicalAdmin();

        $response = $this->dashboard()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', self::SUCCESS_MESSAGE);

        $data = $response->json('data');
        $this->assertSame(['pending_applications', 'recent_reviews', 'stats'], $this->sortedKeys($data));
        $this->assertSame(['active_members', 'events', 'pending_applications', 'reviews'], $this->sortedKeys($data['stats']));
        $this->assertSame(['new_this_month', 'total'], $this->sortedKeys($data['stats']['active_members']));
        $this->assertSame(['total'], $this->sortedKeys($data['stats']['pending_applications']));
        $this->assertSame(['this_month', 'this_week'], $this->sortedKeys($data['stats']['events']));
        $this->assertSame(['flagged', 'new_this_week'], $this->sortedKeys($data['stats']['reviews']));

        $this->assertSame(0, $data['stats']['active_members']['total']);
        $this->assertSame(0, $data['stats']['active_members']['new_this_month']);
        $this->assertSame(0, $data['stats']['pending_applications']['total']);
        $this->assertSame(0, $data['stats']['events']['this_month']);
        $this->assertSame(0, $data['stats']['events']['this_week']);
        $this->assertSame(0, $data['stats']['reviews']['flagged']);
        $this->assertSame(0, $data['stats']['reviews']['new_this_week']);
        $this->assertSame([], $data['pending_applications']);
        $this->assertSame([], $data['recent_reviews']);

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }

    #[Test]
    public function active_members_are_users_in_trial_active_or_grace_including_promoted_admins(): void
    {
        $this->actingAsTechnicalAdmin();

        User::factory()->create();
        User::factory()->create(['trial_ends_at' => now()->addDays(10)]);
        $this->inGrace(User::factory()->create());
        User::factory()->admin()->create();
        User::factory()->admin()->create(['trial_ends_at' => now()->addDays(10)]);

        User::factory()->technicalAdmin()->create();
        User::factory()->pending()->create();
        User::factory()->approved()->create();
        User::factory()->inactive(InactiveReason::Blocked)->create();
        User::factory()->inactive(InactiveReason::Unpaid)->create();
        User::factory()->inactive(InactiveReason::Cancelled)->create();
        $this->withProfile(User::factory()->create())->anonymizeAndDelete();

        $this->dashboard()->assertJsonPath('data.stats.active_members.total', 5);

        $counts = $this->getJson('/api/admin/users?include_admins=1')
            ->assertOk()
            ->json('data.subscription_state_counts');
        $this->assertSame(5, $counts['trial'] + $counts['active'] + $counts['grace']);
    }

    #[Test]
    public function new_members_this_month_are_counted_by_their_first_subscription_in_lisbon(): void
    {
        $this->nowInLisbon('2026-10-29 12:00:00');
        $this->actingAsTechnicalAdmin();

        $this->subscription(User::factory()->create(), '2026-10-05 10:00:00');

        $returning = User::factory()->create();
        $this->subscription($returning, '2026-09-10 10:00:00');
        $this->subscription($returning, '2026-10-10 10:00:00');

        $this->subscription(User::factory()->create(), '2026-09-30 23:30:00');
        $this->subscription(User::factory()->create(), '2026-09-30 22:59:59');
        User::factory()->create();
        $this->subscription(User::factory()->create(['trial_ends_at' => now()->addDays(20)]), '2026-10-20 10:00:00');
        $this->subscription(User::factory()->inactive(InactiveReason::Unpaid)->create(), '2026-10-12 10:00:00');

        $response = $this->dashboard();

        $this->assertSame(3, $response->json('data.stats.active_members.new_this_month'));
        $this->assertSame(6, $response->json('data.stats.active_members.total'));
    }

    #[Test]
    public function new_members_this_month_respect_the_month_boundary_after_the_clock_change(): void
    {
        $this->nowInLisbon('2026-11-01 00:30:00');
        $this->actingAsTechnicalAdmin();

        $this->subscription(User::factory()->create(), '2026-10-31 23:59:00');
        $this->subscription(User::factory()->create(), '2026-11-01 00:10:00');

        $this->assertSame(1, $this->dashboard()->json('data.stats.active_members.new_this_month'));
    }

    #[Test]
    public function pending_applications_total_counts_only_pending_accounts_not_deleted(): void
    {
        $this->actingAsTechnicalAdmin();

        $this->withProfile(User::factory()->pending()->create());
        $this->withProfile(User::factory()->pending()->create());
        User::factory()->pending()->create();

        User::factory()->approved()->create();
        User::factory()->create();
        User::factory()->pending()->create()->delete();

        $this->assertSame(3, $this->dashboard()->json('data.stats.pending_applications.total'));
    }

    #[Test]
    public function events_are_counted_in_the_current_month_and_monday_to_sunday_week_in_lisbon(): void
    {
        $this->nowInLisbon('2026-10-29 12:00:00');
        $this->actingAsTechnicalAdmin();

        $this->event('2026-09-30');
        $this->event('2026-10-01');
        $this->event('2026-10-25');
        $this->event('2026-10-26');
        $this->event('2026-10-29', ['event_start_time' => '08:00:00', 'event_end_time' => '09:00:00']);
        $this->event('2026-10-31', [
            'is_online' => true,
            'online_url' => 'https://reunioes.example.pt/aspec',
            'address' => null,
            'latitude' => null,
            'longitude' => null,
        ]);
        $this->event('2026-11-01');
        $this->event('2026-11-02');

        $response = $this->dashboard();

        $this->assertSame(5, $response->json('data.stats.events.this_month'));
        $this->assertSame(4, $response->json('data.stats.events.this_week'));
    }

    #[Test]
    public function events_use_the_lisbon_calendar_when_utc_is_still_in_the_previous_month(): void
    {
        $this->travelTo(Carbon::parse('2026-08-31 23:30:00', 'UTC'));
        $this->actingAsTechnicalAdmin();

        $this->event('2026-08-31');
        $this->event('2026-09-01');

        $response = $this->dashboard();

        $this->assertSame(1, $response->json('data.stats.events.this_month'));
        $this->assertSame(2, $response->json('data.stats.events.this_week'));
    }

    #[Test]
    public function events_this_week_can_cross_into_the_previous_month(): void
    {
        $this->nowInLisbon('2026-11-01 00:30:00');
        $this->actingAsTechnicalAdmin();

        $this->event('2026-10-25');
        $this->event('2026-10-26');
        $this->event('2026-10-31');
        $this->event('2026-11-01');
        $this->event('2026-11-30');
        $this->event('2026-12-01');

        $response = $this->dashboard();

        $this->assertSame(2, $response->json('data.stats.events.this_month'));
        $this->assertSame(3, $response->json('data.stats.events.this_week'));
    }

    #[Test]
    public function each_session_of_a_series_counts_as_an_event(): void
    {
        $this->nowInLisbon('2026-10-29 12:00:00');
        $this->actingAsTechnicalAdmin();

        $series = EventSeries::query()->create([
            'frequency' => 'weekly',
            'ends_on' => '2026-10-20',
            'registration_deadline_days_before' => 1,
        ]);
        foreach (['2026-10-06', '2026-10-13', '2026-10-20'] as $date) {
            $this->event($date, ['series_id' => $series->id]);
        }
        $this->event('2026-10-15');

        $this->assertSame(4, $this->dashboard()->json('data.stats.events.this_month'));
    }

    #[Test]
    public function flagged_reviews_count_only_reviews_with_the_flagged_status(): void
    {
        $this->actingAsTechnicalAdmin();

        $this->review($this->profile(), $this->profile(), 'Visible');
        $this->review($this->profile(), $this->profile(), 'Visible');
        $this->review($this->profile(), $this->profile(), 'Hidden');
        $this->review($this->profile(), $this->profile(), 'Flagged');

        $anonymizedReviewer = $this->profile();
        $this->review($anonymizedReviewer, $this->profile(), 'Flagged');
        $anonymizedReviewer->user->anonymizeAndDelete();

        $this->assertSame(2, $this->dashboard()->json('data.stats.reviews.flagged'));
    }

    #[Test]
    public function new_reviews_this_week_are_counted_by_creation_in_the_lisbon_week(): void
    {
        $this->nowInLisbon('2026-10-22 12:00:00');
        $this->actingAsTechnicalAdmin();

        $this->review($this->profile(), $this->profile(), 'Flagged', '2026-10-18 23:30:00');
        $this->review($this->profile(), $this->profile(), 'Visible', '2026-10-18 22:59:59');
        $this->review($this->profile(), $this->profile(), 'Hidden', '2026-10-21 10:00:00');
        $this->review($this->profile(), $this->profile(), 'Visible', '2026-10-12 10:00:00', '2026-10-21 10:00:00');
        $this->review($this->profile(), $this->profile(), 'Visible', '2026-10-22 08:00:00');

        $response = $this->dashboard();

        $this->assertSame(3, $response->json('data.stats.reviews.new_this_week'));
        $this->assertSame(1, $response->json('data.stats.reviews.flagged'));
    }

    #[Test]
    public function pending_applications_list_has_the_three_most_recent_in_the_admin_list_shape(): void
    {
        $this->nowInLisbon('2026-10-29 12:00:00');
        $this->actingAsTechnicalAdmin();

        $pending = [];
        foreach (['2026-10-25', '2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29'] as $date) {
            $pending[$date] = $this->withProfile(User::factory()->pending()->create(['created_at' => "{$date} 09:00:00"]));
        }
        $this->withProfile(User::factory()->create(['created_at' => '2026-10-29 11:00:00']));

        $items = $this->dashboard()->json('data.pending_applications');

        $this->assertSame(
            [$pending['2026-10-29']->id, $pending['2026-10-28']->id, $pending['2026-10-27']->id],
            array_column($items, 'id')
        );
        foreach ($items as $item) {
            $this->assertSame(
                ['account_status', 'created_at', 'email', 'id', 'inactive_reason', 'member_profile', 'role', 'subscription_state'],
                $this->sortedKeys($item)
            );
            $this->assertSame(['business_name', 'location', 'name', 'sector'], $this->sortedKeys($item['member_profile']));
            $this->assertSame('pending', $item['subscription_state']['value']);
            $this->assertArrayNotHasKey('phone', $item);
        }

        $withoutProfile = User::factory()->pending()->create(['created_at' => '2026-10-29 11:30:00']);

        $first = $this->dashboard()->json('data.pending_applications.0');
        $this->assertSame($withoutProfile->id, $first['id']);
        $this->assertNull($first['member_profile']);
    }

    #[Test]
    public function recent_reviews_list_has_the_three_last_updated_with_only_business_names(): void
    {
        $this->nowInLisbon('2026-10-29 12:00:00');
        $this->actingAsTechnicalAdmin();

        $edited = $this->review($this->profile(), $this->profile('Padaria São João'), 'Flagged', '2026-10-01 10:00:00', '2026-10-28 10:00:00', 1);
        $second = $this->review($this->profile(), $this->profile(), 'Visible', '2026-10-27 10:00:00');
        $third = $this->review($this->profile(), $this->profile(), 'Visible', '2026-10-26 10:00:00');
        $this->review($this->profile(), $this->profile(), 'Hidden', '2026-10-25 10:00:00');
        $this->review($this->profile(), $this->profile(), 'Visible', '2026-10-24 10:00:00');

        $items = $this->dashboard()->json('data.recent_reviews');

        $this->assertSame([$edited->id, $second->id, $third->id], array_column($items, 'id'));
        foreach ($items as $item) {
            $this->assertSame(['id', 'rating', 'reviewed', 'reviewer', 'status'], $this->sortedKeys($item));
            $this->assertSame(['business_name'], array_keys($item['reviewed']));
            $this->assertSame(['business_name'], array_keys($item['reviewer']));
            $this->assertIsInt($item['rating']);
        }
        $this->assertSame(1, $items[0]['rating']);
        $this->assertSame('Flagged', $items[0]['status']);
        $this->assertSame('Padaria São João', $items[0]['reviewed']['business_name']);
    }

    #[Test]
    public function recent_review_by_an_anonymized_member_shows_the_removed_business_name(): void
    {
        $this->actingAsTechnicalAdmin();

        $ana = $this->profile('Ana Lda.');
        $bruno = $this->profile('Bruno Construções');
        $this->review($bruno, $ana, 'Hidden');
        $bruno->user->anonymizeAndDelete();

        $item = $this->dashboard()->json('data.recent_reviews.0');

        $this->assertSame('Empresa Removida', $item['reviewer']['business_name']);
        $this->assertSame('Ana Lda.', $item['reviewed']['business_name']);
        $this->assertSame('Hidden', $item['status']);
    }

    #[Test]
    public function dashboard_does_not_expose_contacts_billing_or_review_comments(): void
    {
        $this->actingAsTechnicalAdmin();

        $applicant = $this->withProfile(
            User::factory()->pending()->create(['phone' => '+351910000999', 'nif' => '123456789']),
            ['commercial_email' => 'comercial@example.pt']
        );
        $this->review($this->profile(), $applicant->memberProfile, 'Flagged', comment: 'Comentário secreto de teste');

        $content = $this->dashboard()->getContent();

        foreach (['+351910000999', 'comercial@example.pt', '123456789', 'Comentário secreto de teste', '"comment"', '"phone"', '"nif"', '"stripe'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $content);
        }
    }

    #[Test]
    public function dashboard_runs_the_same_number_of_queries_with_little_or_much_data(): void
    {
        $this->nowInLisbon('2026-10-29 12:00:00');
        $this->actingAsTechnicalAdmin();

        $pending = $this->withProfile(User::factory()->pending()->create());
        $active = $this->withProfile(User::factory()->create());
        $this->subscription($active, '2026-10-05 10:00:00');
        $this->event('2026-10-30');
        $this->review($active->memberProfile, $pending->memberProfile);

        // Pedido de aquecimento: os middlewares carregam o papel e o estado do admin só no primeiro pedido.
        $this->dashboard();
        $small = $this->countQueries();

        for ($i = 0; $i < 4; $i++) {
            $this->withProfile(User::factory()->pending()->create());
        }
        $this->withProfile(User::factory()->create(['trial_ends_at' => now()->addDays(10)]));
        $this->withProfile($this->inGrace(User::factory()->create()));
        $this->withProfile(User::factory()->admin()->create());
        $this->subscription($this->withProfile(User::factory()->create()), '2026-10-12 10:00:00');
        $this->withProfile(User::factory()->create());
        foreach (['2026-10-01', '2026-10-15', '2026-10-27', '2026-10-31', '2026-11-01'] as $date) {
            $this->event($date);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->review($this->profile(), $this->profile(), $i % 2 === 0 ? 'Visible' : 'Flagged');
        }

        $large = $this->countQueries();

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(25, $large);
    }

    #[Test]
    public function dashboard_reflects_an_approval_immediately(): void
    {
        $this->actingAsTechnicalAdmin();
        $applicant = $this->withProfile(User::factory()->pending()->create());

        $before = $this->dashboard();
        $before->assertJsonPath('data.stats.pending_applications.total', 1);
        $this->assertSame([$applicant->id], array_column($before->json('data.pending_applications'), 'id'));

        $this->patchJson("/api/admin/users/{$applicant->id}/approve")->assertOk();

        $this->dashboard()
            ->assertJsonPath('data.stats.pending_applications.total', 0)
            ->assertJsonPath('data.pending_applications', []);
    }

    #[Test]
    public function dashboard_is_limited_to_60_requests_per_minute_per_admin(): void
    {
        $this->freezeTime();
        $this->actingAsTechnicalAdmin();

        for ($i = 1; $i <= 60; $i++) {
            $this->getJson(self::URL)->assertOk();
        }

        $this->assertJsonError($this->getJson(self::URL), 429, 'Demasiados pedidos. Tente novamente mais tarde.');

        $this->actingAsTechnicalAdmin();
        $this->getJson(self::URL)->assertOk();
    }

    /**
     * @return list<string>
     */
    private function sortedKeys(array $data): array
    {
        $keys = array_keys($data);
        sort($keys);

        return $keys;
    }

    private function countQueries(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->dashboard();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
