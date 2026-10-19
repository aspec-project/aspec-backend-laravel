<?php

namespace Tests\Feature\Seeders;

use App\Enums\InactiveReason;
use App\Models\EventCategory;
use App\Models\EventInstance;
use App\Models\MemberProfile;
use App\Models\Portfolio;
use App\Models\User;
use App\Rules\InternationalPhone;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Seeder de dados de demonstração (php artisan db:seed --class=DemoSeeder).
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * Corre o seeder como na linha de comandos; o --force evita a confirmação do Laravel em
     * produção, para ser a proteção do próprio seeder a decidir.
     */
    private function runDemoSeeder(): void
    {
        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])->assertSuccessful();
    }

    private function demoUser(string $email): User
    {
        return User::with(['role', 'accountStatus', 'memberProfile'])->where('email', $email)->firstOrFail();
    }

    private function activeProfiles()
    {
        return MemberProfile::listedInDirectory()->get();
    }

    #[Test]
    public function creates_accounts_with_the_right_role_status_and_reason(): void
    {
        $this->runDemoSeeder();

        $expected = [
            'demo.admin@aspec.test' => ['Admin', 'Active', null],
            'demo.membro@aspec.test' => ['Member', 'Active', null],
            'demo.pendente01@aspec.test' => ['Member', 'Pending', null],
            'demo.pendente02@aspec.test' => ['Member', 'Pending', null],
            'demo.pendente03@aspec.test' => ['Member', 'Pending', null],
            'demo.aprovado@aspec.test' => ['Member', 'Approved', null],
            'demo.inativo.pagamento@aspec.test' => ['Member', 'Inactive', InactiveReason::Unpaid],
            'demo.bloqueado@aspec.test' => ['Member', 'Inactive', InactiveReason::Blocked],
        ];

        foreach (range(1, 11) as $number) {
            $expected[sprintf('demo.membro%02d@aspec.test', $number)] = ['Member', 'Active', null];
        }

        foreach ($expected as $email => [$role, $status, $reason]) {
            $user = $this->demoUser($email);

            $this->assertSame($role, $user->role->name, $email);
            $this->assertSame($status, $user->accountStatus->name, $email);
            $this->assertSame($reason, $user->inactive_reason, $email);
            $this->assertNotNull($user->email_verified_at, $email);
            $this->assertNotNull($user->memberProfile, $email);
            $this->assertMatchesRegularExpression('/^9[1236]\d{7}$/', $user->phone, $email);
        }

        $this->assertSame(19, User::where('email', 'like', 'demo.%@aspec.test')->count());
    }

    #[Test]
    public function creates_thirteen_complete_active_profiles_with_varied_sectors_and_districts(): void
    {
        $this->runDemoSeeder();

        $profiles = $this->activeProfiles();

        $this->assertCount(13, $profiles);
        $this->assertGreaterThanOrEqual(8, $profiles->pluck('sector_id')->unique()->count());
        $this->assertGreaterThanOrEqual(8, $profiles->pluck('location_id')->unique()->count());

        $districts = $profiles->load('location')->pluck('location.name');

        foreach (['Lisboa', 'Porto', 'Braga', 'Coimbra', 'Faro', 'Aveiro', 'Leiria', 'Setúbal'] as $district) {
            $this->assertContains($district, $districts);
        }

        foreach ($profiles->load(['socialPlatforms', 'weekDays']) as $profile) {
            foreach (['description', 'website_url', 'commercial_email', 'commercial_phone', 'address', 'congregation', 'role_in_congregation'] as $field) {
                $this->assertNotEmpty($profile->{$field}, "{$profile->business_name}: {$field}");
            }

            $this->assertGreaterThanOrEqual(1, $profile->socialPlatforms->count());
            $this->assertLessThanOrEqual(3, $profile->socialPlatforms->count());
        }

        $withoutHours = $profiles->filter(fn (MemberProfile $profile) => $profile->weekDays->isEmpty());
        $this->assertGreaterThanOrEqual(1, $withoutHours->count());
        $this->assertLessThanOrEqual(2, $withoutHours->count());
    }

    #[Test]
    public function every_demo_profile_has_valid_commercial_email_and_e164_phone(): void
    {
        $this->runDemoSeeder();

        $profiles = MemberProfile::whereHas('user', fn ($query) => $query->where('email', 'like', 'demo.%@aspec.test'))->get();
        $this->assertNotEmpty($profiles);

        foreach ($profiles as $profile) {
            $validator = Validator::make(
                $profile->only(['commercial_email', 'commercial_phone']),
                [
                    'commercial_email' => 'required|string|email:rfc|max:255',
                    'commercial_phone' => ['required', 'string', new InternationalPhone],
                ],
            );

            $this->assertFalse($validator->fails(), "{$profile->business_name}: ".$validator->errors()->toJson());
            $this->assertSame(InternationalPhone::normalize($profile->commercial_phone), $profile->commercial_phone, $profile->business_name);
        }
    }

    #[Test]
    public function creates_about_ten_future_events_in_every_category(): void
    {
        $this->runDemoSeeder();

        $events = EventInstance::with('location')->get();

        $this->assertGreaterThanOrEqual(9, $events->count());
        $this->assertLessThanOrEqual(12, $events->count());
        $this->assertSame(EventCategory::count(), $events->pluck('category_id')->unique()->count());
        $this->assertGreaterThanOrEqual(6, $events->pluck('location_id')->unique()->count());

        foreach ($events as $event) {
            $this->assertTrue($event->event_date->between(today()->addDays(5), today()->addDays(60)), $event->name);
            $this->assertTrue($event->registration_deadline->lessThan($event->event_date), $event->name);
            $this->assertGreaterThanOrEqual(20, $event->max_attendees);
            $this->assertLessThanOrEqual(80, $event->max_attendees);
        }

        $replicated = $events->groupBy('name')->filter(fn ($sameFormat) => $sameFormat->pluck('location_id')->unique()->count() > 1);
        $this->assertGreaterThanOrEqual(2, $replicated->count());
    }

    #[Test]
    public function demo_events_use_the_five_real_categories(): void
    {
        $this->runDemoSeeder();

        foreach (['Pequeno-almoço', 'Almoço', 'Jantar', 'Workshop', 'Formação'] as $name) {
            $this->assertTrue(
                EventInstance::whereHas('category', fn ($query) => $query->where('name', $name))->exists(),
                $name
            );
        }

        $this->assertSame(5, EventCategory::count());
        $this->assertFalse(EventInstance::where('name', 'Reunião Regional de Membros')->exists());
    }

    #[Test]
    #[RequiresPhpExtension('gd')]
    public function stores_logos_and_portfolio_images_on_the_public_disk(): void
    {
        $this->runDemoSeeder();

        foreach ($this->activeProfiles() as $profile) {
            $this->assertNotNull($profile->logo_path, $profile->business_name);
            $this->assertStringStartsWith("logos/{$profile->user_id}/", $profile->logo_path);
            Storage::disk('public')->assertExists($profile->logo_path);
        }

        $profilesWithPortfolio = MemberProfile::has('portfolios')->with('portfolios')->get();
        $this->assertGreaterThanOrEqual(4, $profilesWithPortfolio->count());

        foreach ($profilesWithPortfolio as $profile) {
            $this->assertGreaterThanOrEqual(3, $profile->portfolios->count());

            foreach ($profile->portfolios as $image) {
                $this->assertStringStartsWith("portfolios/{$profile->user_id}/", $image->image_path);
                Storage::disk('public')->assertExists($image->image_path);
            }
        }
    }

    #[Test]
    public function running_twice_does_not_duplicate_anything(): void
    {
        $this->runDemoSeeder();

        $counts = fn () => [
            'users' => User::count(),
            'profiles' => MemberProfile::count(),
            'events' => EventInstance::count(),
            'portfolios' => Portfolio::count(),
            'files' => count(Storage::disk('public')->allFiles()),
        ];

        $first = $counts();

        $this->runDemoSeeder();

        $this->assertSame($first, $counts());
    }

    #[Test]
    public function refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])
            ->expectsOutputToContain('O DemoSeeder não pode correr em produção.')
            ->assertSuccessful();

        $this->assertSame(0, User::where('email', 'like', 'demo.%')->count());
        $this->assertSame(0, MemberProfile::count());
        $this->assertSame(0, EventInstance::count());
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    #[Test]
    public function directory_and_agenda_endpoints_return_the_demo_data(): void
    {
        $this->runDemoSeeder();

        $this->getJson('/api/members?per_page=50')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 13)
            ->assertJsonCount(13, 'data.items');

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', EventInstance::count());
    }

    #[Test]
    public function demo_member_can_log_in_with_the_demo_password(): void
    {
        $this->runDemoSeeder();

        $this->postJson('/api/auth/token', [
            'email' => 'demo.membro@aspec.test',
            'password' => DemoSeeder::PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('Demo#2026', DemoSeeder::PASSWORD);
    }
}
