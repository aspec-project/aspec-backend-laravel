<?php

namespace Tests\Feature\Admin;

use App\Enums\InactiveReason;
use App\Models\EventInstance;
use App\Models\EventRegistration;
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

    private const APPLICATIONS_URL = '/api/admin/dashboard/pending-applications';

    private const APPLICATIONS_MESSAGE = 'Candidaturas pendentes obtidas com sucesso.';

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

    private function register(User $user, EventInstance $event, ?string $createdAtUtc = null): EventRegistration
    {
        $registration = EventRegistration::query()->create([
            'event_instance_id' => $event->id,
            'user_id' => $user->id,
        ]);

        if ($createdAtUtc !== null) {
            DB::table('event_registrations')->where('id', $registration->id)->update(['created_at' => $createdAtUtc]);
        }

        return $registration;
    }

    private function dashboard(): TestResponse
    {
        return $this->getJson(self::URL)->assertOk();
    }

    private function applications(?int $page = null): TestResponse
    {
        $url = $page === null ? self::APPLICATIONS_URL : self::APPLICATIONS_URL.'?page='.$page;

        return $this->getJson($url)->assertOk();
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
        $this->assertSame(
            ['active_members', 'event_registrations', 'events', 'overdue_payments', 'pending_applications', 'reviews'],
            $this->sortedKeys($data['stats'])
        );
        $this->assertSame(['active', 'admins', 'new_this_month', 'total', 'trial'], $this->sortedKeys($data['stats']['active_members']));
        $this->assertSame(['total'], $this->sortedKeys($data['stats']['pending_applications']));
        $this->assertSame(['this_month', 'this_week'], $this->sortedKeys($data['stats']['events']));
        $this->assertSame(['flagged', 'new_this_week'], $this->sortedKeys($data['stats']['reviews']));
        $this->assertSame(['grace', 'total', 'unpaid'], $this->sortedKeys($data['stats']['overdue_payments']));
        $this->assertSame(['events_with_registrations', 'this_week'], $this->sortedKeys($data['stats']['event_registrations']));

        $this->assertSame(0, $data['stats']['active_members']['total']);
        $this->assertSame(0, $data['stats']['active_members']['new_this_month']);
        $this->assertSame(0, $data['stats']['active_members']['trial']);
        $this->assertSame(0, $data['stats']['active_members']['active']);
        $this->assertSame(0, $data['stats']['active_members']['admins']);
        $this->assertSame(0, $data['stats']['pending_applications']['total']);
        $this->assertSame(0, $data['stats']['events']['this_month']);
        $this->assertSame(0, $data['stats']['events']['this_week']);
        $this->assertSame(0, $data['stats']['reviews']['flagged']);
        $this->assertSame(0, $data['stats']['reviews']['new_this_week']);
        $this->assertSame(0, $data['stats']['overdue_payments']['total']);
        $this->assertSame(0, $data['stats']['overdue_payments']['grace']);
        $this->assertSame(0, $data['stats']['overdue_payments']['unpaid']);
        $this->assertSame(0, $data['stats']['event_registrations']['this_week']);
        $this->assertSame(0, $data['stats']['event_registrations']['events_with_registrations']);
        $this->assertSame([], $data['pending_applications']);
        $this->assertSame([], $data['recent_reviews']);

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }

    #[Test]
    public function active_members_are_users_in_trial_or_active_including_promoted_admins(): void
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

        $this->assertSame(4, $this->dashboard()->json('data.stats.active_members.total'));

        $counts = $this->getJson('/api/admin/users?include_admins=1')
            ->assertOk()
            ->json('data.subscription_state_counts');
        $this->assertSame(4, $counts['trial'] + $counts['active']);
        $this->assertSame(1, $counts['grace']);
    }

    #[Test]
    public function active_members_are_split_into_trial_and_active_and_count_promoted_admins(): void
    {
        $this->nowInLisbon('2026-11-11 12:00:00');
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

        $activeMembers = $this->dashboard()->json('data.stats.active_members');

        $this->assertSame(4, $activeMembers['total'] ?? null);
        $this->assertSame(0, $activeMembers['new_this_month'] ?? null);
        $this->assertSame(2, $activeMembers['trial'] ?? null);
        $this->assertSame(2, $activeMembers['active'] ?? null);
        $this->assertSame(2, $activeMembers['admins'] ?? null);
        $this->assertSame($activeMembers['total'], $activeMembers['trial'] + $activeMembers['active']);

        $counts = $this->getJson('/api/admin/users?include_admins=1')
            ->assertOk()
            ->json('data.subscription_state_counts');
        $this->assertSame($counts['trial'], $activeMembers['trial']);
        $this->assertSame($counts['active'], $activeMembers['active']);
    }

    #[Test]
    public function admins_in_active_members_leave_out_the_technical_admin_and_admins_in_grace_or_inactive(): void
    {
        $this->nowInLisbon('2026-11-11 12:00:00');
        $this->actingAsTechnicalAdmin();

        User::factory()->create();
        User::factory()->create(['trial_ends_at' => now()->addDays(10)]);

        User::factory()->technicalAdmin()->create();
        $this->inGrace(User::factory()->admin()->create());
        User::factory()->admin()->inactive(InactiveReason::Unpaid)->create();

        $stats = $this->dashboard()->json('data.stats');

        $this->assertSame(0, $stats['active_members']['admins'] ?? null);
        $this->assertSame(1, $stats['active_members']['trial'] ?? null);
        $this->assertSame(1, $stats['active_members']['active'] ?? null);
        $this->assertSame(2, $stats['active_members']['total']);
        $this->assertSame(1, $stats['overdue_payments']['grace']);
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
        $this->register($active, $this->event('2026-10-30'));
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
        $this->inGrace(User::factory()->create());
        $this->inGrace(User::factory()->create());
        User::factory()->inactive(InactiveReason::Unpaid)->create();
        User::factory()->inactive(InactiveReason::Unpaid)->create();
        User::factory()->inactive(InactiveReason::Cancelled)->create();
        $events = [];
        foreach (['2026-10-01', '2026-10-15', '2026-10-27', '2026-10-31', '2026-11-01'] as $date) {
            $events[$date] = $this->event($date);
        }
        $admin = User::factory()->admin()->create();
        $members = User::factory()->count(2)->create();
        foreach (['2026-10-27', '2026-10-31', '2026-11-01'] as $date) {
            $this->register($members[0], $events[$date]);
        }
        $this->register($members[1], $events['2026-10-27']);
        $this->register($members[1], $events['2026-10-31']);
        $this->register($admin, $events['2026-11-01']);
        for ($i = 0; $i < 5; $i++) {
            $this->review($this->profile(), $this->profile(), $i % 2 === 0 ? 'Visible' : 'Flagged');
        }

        $large = $this->countQueries();
        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(25, $large);
        $this->assertSame(19, $large);
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

    #[Test]
    public function overdue_payments_count_accounts_in_grace_and_inactive_for_not_paying(): void
    {
        $this->nowInLisbon('2026-10-29 12:00:00');
        $this->actingAsTechnicalAdmin();
        $this->overduePaymentsScenario();

        $overdue = $this->dashboard()->json('data.stats.overdue_payments');

        $this->assertSame(['total' => 5, 'grace' => 3, 'unpaid' => 2], $overdue);
    }

    #[Test]
    public function overdue_payments_match_the_subscription_state_counts_of_the_users_list(): void
    {
        $this->nowInLisbon('2026-10-29 12:00:00');
        $this->actingAsTechnicalAdmin();
        $cancelled = $this->overduePaymentsScenario();

        $counts = $this->getJson('/api/admin/users?include_admins=1')
            ->assertOk()
            ->json('data.subscription_state_counts');
        $this->assertSame(3, $counts['grace']);
        $this->assertSame(3, $counts['lapsed']);

        $response = $this->dashboard();

        $this->assertSame($counts['grace'], $response->json('data.stats.overdue_payments.grace'));
        $this->assertSame($counts['lapsed'] - $cancelled, $response->json('data.stats.overdue_payments.unpaid'));
    }

    #[Test]
    public function registrations_this_week_count_rows_by_the_event_date_in_the_lisbon_week(): void
    {
        $this->nowInLisbon('2026-10-29 12:00:00');
        $this->actingAsTechnicalAdmin();
        [$a, $b, $c, $d] = User::factory()->count(4)->create()->all();

        $sunday = $this->event('2026-10-25');
        $this->register($a, $sunday);
        $this->register($b, $sunday);
        $this->register($a, $this->event('2026-10-26'));
        $this->register($b, $this->event('2026-10-29', ['event_start_time' => '08:00:00', 'event_end_time' => '09:00:00']));
        $online = $this->event('2026-10-31', [
            'is_online' => true,
            'online_url' => 'https://reunioes.example.pt/aspec',
            'address' => null,
            'latitude' => null,
            'longitude' => null,
        ]);
        $this->register($a, $online, '2026-10-01 10:00:00');
        $this->register($c, $online);
        $this->register($d, $this->event('2026-11-01'));
        $nextMonday = $this->event('2026-11-02');
        foreach ([$a, $b, $c] as $member) {
            $this->register($member, $nextMonday);
        }
        $this->event('2026-10-30');

        $response = $this->dashboard();

        $this->assertSame(5, $response->json('data.stats.event_registrations.this_week'));
        $this->assertSame(4, $response->json('data.stats.event_registrations.events_with_registrations'));
        $this->assertSame(5, $response->json('data.stats.events.this_week'));
    }

    #[Test]
    public function registrations_this_week_use_the_lisbon_week_when_utc_is_still_in_the_previous_week(): void
    {
        $this->travelTo(Carbon::parse('2026-09-06 23:30:00', 'UTC'));
        $this->actingAsTechnicalAdmin();
        $member = User::factory()->create();

        foreach (['2026-09-06', '2026-09-07', '2026-09-13', '2026-09-14'] as $date) {
            $this->register($member, $this->event($date));
        }

        $registrations = $this->dashboard()->json('data.stats.event_registrations');

        $this->assertSame(2, $registrations['this_week']);
        $this->assertSame(2, $registrations['events_with_registrations'] ?? null);
    }

    #[Test]
    public function registrations_this_week_respect_the_week_boundaries_on_clock_change_days(): void
    {
        $member = User::factory()->create();
        foreach (['2026-10-18', '2026-10-19', '2026-10-25', '2026-10-26', '2026-03-29', '2026-03-30', '2026-04-05'] as $date) {
            $this->register($member, $this->event($date));
        }

        $this->nowInLisbon('2026-10-25 23:30:00');
        $this->actingAsTechnicalAdmin();
        $registrations = $this->dashboard()->json('data.stats.event_registrations');
        $this->assertSame(2, $registrations['this_week']);
        $this->assertSame(2, $registrations['events_with_registrations'] ?? null);

        $this->nowInLisbon('2026-03-30 00:15:00');
        $registrations = $this->dashboard()->json('data.stats.event_registrations');
        $this->assertSame(2, $registrations['this_week']);
        $this->assertSame(2, $registrations['events_with_registrations'] ?? null);
    }

    #[Test]
    public function each_series_session_counts_only_its_own_registrations(): void
    {
        $this->nowInLisbon('2026-10-29 12:00:00');
        $this->actingAsTechnicalAdmin();
        [$a, $b] = User::factory()->count(2)->create()->all();

        $series = EventSeries::query()->create([
            'frequency' => 'weekly',
            'ends_on' => '2026-11-09',
            'registration_deadline_days_before' => 1,
        ]);
        $sessions = [];
        foreach (['2026-10-26', '2026-11-02', '2026-11-09'] as $date) {
            $sessions[$date] = $this->event($date, ['series_id' => $series->id]);
            $this->register($a, $sessions[$date]);
        }
        $this->register($b, $sessions['2026-10-26']);

        $registrations = $this->dashboard()->json('data.stats.event_registrations');

        $this->assertSame(2, $registrations['this_week']);
        $this->assertSame(1, $registrations['events_with_registrations'] ?? null);
    }

    #[Test]
    public function registrations_this_week_include_anonymized_blocked_and_admin_accounts(): void
    {
        $this->nowInLisbon('2026-10-29 12:00:00');
        $this->actingAsTechnicalAdmin();

        $anonymized = $this->withProfile(User::factory()->create());
        $tuesday = $this->event('2026-10-27');
        $past = $this->register($anonymized, $tuesday);
        $future = $this->register($anonymized, $this->event('2026-10-31'));
        $anonymized->anonymizeAndDelete();
        $this->assertDatabaseHas('event_registrations', ['id' => $past->id]);
        $this->assertDatabaseMissing('event_registrations', ['id' => $future->id]);

        $friday = $this->event('2026-10-30');
        $blocked = $this->withProfile(User::factory()->create());
        $this->register($blocked, $tuesday);
        $this->patchJson("/api/admin/users/{$blocked->id}/block")->assertOk();
        $this->register(User::factory()->admin()->create(), $friday);

        $this->assertDatabaseCount('event_registrations', 3);
        $registrations = $this->dashboard()->json('data.stats.event_registrations');
        $this->assertSame(3, $registrations['this_week']);
        $this->assertSame(2, $registrations['events_with_registrations'] ?? null);
    }

    #[Test]
    public function blocking_lowers_this_week_registrations_on_the_dashboard(): void
    {
        $this->nowInLisbon('2026-10-29 12:00:00');
        $this->actingAsTechnicalAdmin();

        $member = $this->withProfile(User::factory()->create());
        $this->register($member, $this->event('2026-10-27'));
        $this->register($member, $this->event('2026-10-30'));

        $registrations = $this->dashboard()->json('data.stats.event_registrations');
        $this->assertSame(2, $registrations['this_week']);
        $this->assertSame(2, $registrations['events_with_registrations'] ?? null);

        $this->patchJson("/api/admin/users/{$member->id}/block")->assertOk();

        $registrations = $this->dashboard()->json('data.stats.event_registrations');
        $this->assertSame(1, $registrations['this_week']);
        $this->assertSame(1, $registrations['events_with_registrations'] ?? null);
    }

    #[Test]
    public function being_in_grace_does_not_give_admin_access_and_a_promoted_admin_in_grace_counts_himself(): void
    {
        Sanctum::actingAs($this->inGrace(User::factory()->create()));
        $this->assertJsonError($this->getJson(self::URL), 403, self::FORBIDDEN_MESSAGE);

        Sanctum::actingAs($this->inGrace(User::factory()->admin()->create()));
        $this->dashboard()
            ->assertJsonPath('data.stats.overdue_payments.grace', 2)
            ->assertJsonPath('data.stats.overdue_payments.total', 2);
    }

    #[Test]
    public function overdue_payments_and_registrations_do_not_expose_personal_data(): void
    {
        $this->nowInLisbon('2026-10-29 12:00:00');
        $this->actingAsTechnicalAdmin();

        $registrant = User::factory()->create(['email' => 'inscrito.semana@example.pt', 'phone' => '+351910000777']);
        $this->register($registrant, $this->event('2026-10-30'));
        $this->inGrace(User::factory()->create(['email' => 'carencia@example.pt']));

        $content = $this->dashboard()->getContent();

        foreach (['inscrito.semana@example.pt', '+351910000777', 'carencia@example.pt', '"user_id"', '"grace_ends_at"', '"stripe'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $content);
        }
    }

    #[Test]
    public function new_members_this_month_and_total_leave_out_accounts_in_grace(): void
    {
        $this->nowInLisbon('2026-10-29 12:00:00');
        $this->actingAsTechnicalAdmin();

        $this->subscription(User::factory()->create(), '2026-10-10 10:00:00');
        $this->subscription(User::factory()->create(['trial_ends_at' => now()->addDays(20)]), '2026-10-20 10:00:00');
        $this->subscription($this->inGrace(User::factory()->create()), '2026-10-05 10:00:00');
        $this->subscription($this->inGrace(User::factory()->admin()->create()), '2026-10-06 10:00:00');

        $activeMembers = $this->dashboard()->json('data.stats.active_members');

        $this->assertSame(2, $activeMembers['new_this_month']);
        $this->assertSame(2, $activeMembers['total']);
    }

    #[Test]
    public function an_account_in_grace_is_counted_only_in_overdue_payments_not_in_active_members(): void
    {
        $this->actingAsTechnicalAdmin();

        User::factory()->create(['trial_ends_at' => now()->addDays(10)]);
        User::factory()->create();
        $this->inGrace(User::factory()->create());
        $this->inGrace(User::factory()->admin()->create());
        $this->inGrace(User::factory()->technicalAdmin()->create());

        $stats = $this->dashboard()->json('data.stats');

        $this->assertSame(2, $stats['active_members']['total']);
        $this->assertSame(2, $stats['overdue_payments']['grace']);

        $activeNotExempt = User::query()
            ->where('subscription_exempt', false)
            ->whereHas('accountStatus', fn ($status) => $status->where('name', 'Active'))
            ->count();
        $this->assertSame(4, $activeNotExempt);
        $this->assertSame($activeNotExempt, $stats['active_members']['total'] + $stats['overdue_payments']['grace']);
    }

    #[Test]
    public function guests_members_and_inactive_admins_cannot_see_the_pending_applications_page(): void
    {
        $this->assertJsonError($this->getJson(self::APPLICATIONS_URL), 401, 'Não autenticado.');

        Sanctum::actingAs(User::factory()->create());
        $this->assertJsonError($this->getJson(self::APPLICATIONS_URL), 403, self::FORBIDDEN_MESSAGE);

        Sanctum::actingAs(User::factory()->admin()->inactive(InactiveReason::Blocked)->create());
        $this->getJson(self::APPLICATIONS_URL)->assertForbidden();
    }

    #[Test]
    public function pending_applications_page_one_has_the_three_most_recent_by_default(): void
    {
        $this->nowInLisbon('2026-10-29 12:00:00');
        $this->actingAsTechnicalAdmin();
        $pending = $this->fivePendingApplications();

        $response = $this->getJson(self::APPLICATIONS_URL)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', self::APPLICATIONS_MESSAGE);

        $data = $response->json('data');
        $this->assertSame(['items', 'pagination'], $this->sortedKeys($data));
        $this->assertSame(
            [$pending['2026-10-29']->id, $pending['2026-10-28']->id, $pending['2026-10-27']->id],
            array_column($data['items'], 'id')
        );
        $this->assertSame(['current_page' => 1, 'per_page' => 3, 'total' => 5, 'last_page' => 2], $data['pagination']);
        foreach ($data['items'] as $item) {
            $this->assertSame(
                ['account_status', 'created_at', 'email', 'id', 'inactive_reason', 'member_profile', 'role', 'subscription_state'],
                $this->sortedKeys($item)
            );
            $this->assertSame(['business_name', 'location', 'name', 'sector'], $this->sortedKeys($item['member_profile']));
            $this->assertSame('pending', $item['subscription_state']['value']);
        }

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }

    #[Test]
    public function pending_applications_page_two_continues_and_page_one_matches_the_dashboard_list(): void
    {
        $this->nowInLisbon('2026-10-29 12:00:00');
        $this->actingAsTechnicalAdmin();
        $pending = $this->fivePendingApplications();

        $second = $this->applications(2)->json('data');
        $this->assertSame([$pending['2026-10-26']->id, $pending['2026-10-25']->id], array_column($second['items'], 'id'));
        $this->assertSame(2, $second['pagination']['current_page']);

        $this->assertSame(
            array_column($this->applications(1)->json('data.items'), 'id'),
            array_column($this->dashboard()->json('data.pending_applications'), 'id')
        );
    }

    #[Test]
    public function pending_applications_beyond_the_last_page_or_without_applications_return_an_empty_list(): void
    {
        $this->nowInLisbon('2026-10-29 12:00:00');
        $this->actingAsTechnicalAdmin();
        $pending = $this->fivePendingApplications();

        $beyond = $this->applications(3)->json('data');
        $this->assertSame([], $beyond['items']);
        $this->assertSame(['current_page' => 3, 'per_page' => 3, 'total' => 5, 'last_page' => 2], $beyond['pagination']);

        foreach ($pending as $user) {
            $user->delete();
        }

        $empty = $this->applications(1)->json('data');
        $this->assertSame([], $empty['items']);
        $this->assertSame(['current_page' => 1, 'per_page' => 3, 'total' => 0, 'last_page' => 1], $empty['pagination']);

        $this->getJson(self::APPLICATIONS_URL.'?page=')
            ->assertOk()
            ->assertJsonPath('data.pagination.current_page', 1);
    }

    #[Test]
    public function pending_applications_page_must_be_an_integer_from_one_to_ten_thousand(): void
    {
        $this->actingAsTechnicalAdmin();
        $between = 'O campo página tem de estar entre 1 e 10000.';
        $integer = 'O campo página tem de ser um número inteiro.';

        foreach (['0' => $between, '10001' => $between, '-1' => $between, 'abc' => $integer] as $page => $message) {
            $this->getJson(self::APPLICATIONS_URL.'?page='.$page)
                ->assertUnprocessable()
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', 'Os dados enviados são inválidos.')
                ->assertJsonPath('errors.page', [$message]);
        }

        foreach (['page=1.5', 'page[]=1'] as $query) {
            $this->getJson(self::APPLICATIONS_URL.'?'.$query)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['page']);
        }
    }

    #[Test]
    public function pending_applications_page_size_is_always_three(): void
    {
        $this->actingAsTechnicalAdmin();
        for ($i = 0; $i < 5; $i++) {
            $this->withProfile(User::factory()->pending()->create());
        }

        $data = $this->getJson(self::APPLICATIONS_URL.'?per_page=50')->assertOk()->json('data');

        $this->assertCount(3, $data['items']);
        $this->assertSame(3, $data['pagination']['per_page']);
    }

    #[Test]
    public function pending_applications_with_the_same_date_are_paged_by_id_without_repeats(): void
    {
        $this->nowInLisbon('2026-10-29 12:00:00');
        $this->actingAsTechnicalAdmin();

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->withProfile(User::factory()->pending()->create(['created_at' => '2026-10-28 09:00:00']))->id;
        }
        $withoutProfile = User::factory()->pending()->create(['created_at' => '2026-10-28 09:00:00']);
        $ids[] = $withoutProfile->id;
        usort($ids, fn (string $a, string $b) => strcmp($b, $a));

        $first = $this->applications(1)->json('data.items');
        $second = $this->applications(2)->json('data.items');

        $this->assertCount(3, $first);
        $this->assertCount(1, $second);
        $this->assertSame($ids, array_merge(array_column($first, 'id'), array_column($second, 'id')));

        $item = collect(array_merge($first, $second))->firstWhere('id', $withoutProfile->id);
        $this->assertNull($item['member_profile']);
    }

    #[Test]
    public function pending_applications_page_does_not_expose_contacts_billing_or_dashboard_data(): void
    {
        $this->actingAsTechnicalAdmin();
        $this->withProfile(
            User::factory()->pending()->create([
                'phone' => '+351910000888',
                'nif' => '123456780',
                'billing_address' => 'Rua Fictícia 1',
            ]),
            ['commercial_email' => 'comercial.candidato@example.pt']
        );

        $content = $this->applications(1)->getContent();

        $forbidden = ['+351910000888', '123456780', 'Rua Fictícia 1', 'comercial.candidato@example.pt', '"phone"', '"nif"', '"stripe', '"counts"', '"subscription_state_counts"', '"stats"'];
        foreach ($forbidden as $text) {
            $this->assertStringNotContainsString($text, $content);
        }
    }

    #[Test]
    public function pending_applications_page_runs_the_same_number_of_queries_with_one_or_many_applications(): void
    {
        $this->actingAsTechnicalAdmin();
        $this->withProfile(User::factory()->pending()->create());

        $this->applications();
        $small = $this->countQueries(self::APPLICATIONS_URL);

        for ($i = 0; $i < 7; $i++) {
            $this->withProfile(User::factory()->pending()->create());
        }

        $large = $this->countQueries(self::APPLICATIONS_URL);
        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(10, $large);
    }

    #[Test]
    public function dashboard_and_pending_applications_share_the_60_requests_per_minute_limit(): void
    {
        $this->freezeTime();
        $this->actingAsTechnicalAdmin();

        for ($i = 1; $i <= 30; $i++) {
            $this->getJson(self::URL)->assertOk();
            $this->getJson(self::APPLICATIONS_URL)->assertOk();
        }

        $this->assertJsonError($this->getJson(self::APPLICATIONS_URL), 429, 'Demasiados pedidos. Tente novamente mais tarde.');
        $this->assertJsonError($this->getJson(self::URL), 429, 'Demasiados pedidos. Tente novamente mais tarde.');

        $this->actingAsTechnicalAdmin();
        $this->getJson(self::APPLICATIONS_URL)->assertOk();
    }

    #[Test]
    public function pending_applications_page_reflects_an_approval_immediately(): void
    {
        $this->nowInLisbon('2026-10-29 12:00:00');
        $this->actingAsTechnicalAdmin();
        $newest = null;
        foreach (['2026-10-25', '2026-10-26', '2026-10-27', '2026-10-28'] as $date) {
            $newest = $this->withProfile(User::factory()->pending()->create(['created_at' => "{$date} 09:00:00"]));
        }

        $before = $this->applications(2)->json('data');
        $this->assertCount(1, $before['items']);
        $this->assertSame(4, $before['pagination']['total']);

        $this->patchJson("/api/admin/users/{$newest->id}/approve")->assertOk();

        $after = $this->applications(2)->json('data');
        $this->assertSame([], $after['items']);
        $this->assertSame(3, $after['pagination']['total']);
        $this->assertSame(1, $after['pagination']['last_page']);
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

    /**
     * Cria as contas da regra das quotas em atraso (3 em carência e 2 sem pagamento contam) e devolve o número de canceladas.
     */
    private function overduePaymentsScenario(): int
    {
        $this->inGrace(User::factory()->create());
        $this->inGrace(User::factory()->admin()->create());
        $this->inGrace(User::factory()->create(['trial_ends_at' => now()->addDays(10)]));
        $this->inGrace(User::factory()->technicalAdmin()->create());

        User::factory()->inactive(InactiveReason::Unpaid)->create();
        User::factory()->admin()->inactive(InactiveReason::Unpaid)->create();

        User::factory()->inactive(InactiveReason::Cancelled)->create();
        User::factory()->inactive(InactiveReason::Blocked)->create();
        User::factory()->inactive(InactiveReason::Rejected)->create();
        User::factory()->inactive()->create();
        User::factory()->create();
        User::factory()->create(['trial_ends_at' => now()->addDays(10)]);
        User::factory()->pending()->create();
        User::factory()->approved()->create();
        $this->inGrace($this->withProfile(User::factory()->create()))->anonymizeAndDelete();

        return 1;
    }

    /**
     * Cria 5 candidaturas com perfil (25/10 a 29/10 às 09:00), uma conta ativa e uma candidatura apagada; devolve as 5 por data.
     *
     * @return array<string, User>
     */
    private function fivePendingApplications(): array
    {
        $pending = [];
        foreach (['2026-10-25', '2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29'] as $date) {
            $pending[$date] = $this->withProfile(User::factory()->pending()->create(['created_at' => "{$date} 09:00:00"]));
        }
        $this->withProfile(User::factory()->create(['created_at' => '2026-10-29 11:00:00']));
        User::factory()->pending()->create(['created_at' => '2026-10-29 11:30:00'])->delete();

        return $pending;
    }

    private function countQueries(string $url = self::URL): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
