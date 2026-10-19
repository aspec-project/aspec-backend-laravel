<?php

namespace Tests\Feature\Events;

use App\Models\EventInstance;
use App\Models\EventRegistration;
use App\Models\MemberProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventRegistrantsTest extends TestCase
{
    use RefreshDatabase;

    private function url(EventInstance $event): string
    {
        return "/api/events/{$event->id}/registrants";
    }

    private function createRegistration(EventInstance $event, User $user): EventRegistration
    {
        return EventRegistration::query()->create([
            'event_instance_id' => $event->id,
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function active_member_can_see_only_business_names_of_event_registrants(): void
    {
        $viewer = User::factory()->create();
        $registrant = User::factory()->create();
        $otherEventRegistrant = User::factory()->create();

        MemberProfile::factory()->create([
            'user_id' => $registrant->id,
            'business_name' => 'Empresa Inscrita',
        ]);

        MemberProfile::factory()->create([
            'user_id' => $otherEventRegistrant->id,
            'business_name' => 'Empresa de Outro Evento',
        ]);

        $event = EventInstance::factory()->create();
        $otherEvent = EventInstance::factory()->create();

        $this->createRegistration($event, $registrant);
        $this->createRegistration($otherEvent, $otherEventRegistrant);

        Sanctum::actingAs($viewer);

        $response = $this->getJson($this->url($event))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(
            [['business_name' => 'Empresa Inscrita']],
            $response->json('data')
        );
    }

    #[Test]
    public function active_admin_can_see_event_registrants(): void
    {
        $admin = User::factory()->admin()->create();
        $registrant = User::factory()->create();

        MemberProfile::factory()->create([
            'user_id' => $registrant->id,
            'business_name' => 'Empresa Inscrita',
        ]);

        $event = EventInstance::factory()->create();
        $this->createRegistration($event, $registrant);

        Sanctum::actingAs($admin);

        $this->getJson($this->url($event))
            ->assertOk()
            ->assertJsonPath('data.0.business_name', 'Empresa Inscrita');
    }

    #[Test]
    public function guest_cannot_see_event_registrants(): void
    {
        $event = EventInstance::factory()->create();

        $this->getJson($this->url($event))
            ->assertUnauthorized();
    }

    #[Test]
    public function pending_or_inactive_user_cannot_see_event_registrants(): void
    {
        $event = EventInstance::factory()->create();

        foreach ([
            User::factory()->pending()->create(),
            User::factory()->inactive()->create(),
        ] as $user) {
            Sanctum::actingAs($user);

            $this->getJson($this->url($event))
                ->assertForbidden();
        }
    }

    #[Test]
    public function active_user_gets_not_found_for_a_nonexistent_event(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/events/'.Str::uuid().'/registrants')
            ->assertNotFound();
    }

    #[Test]
    public function event_without_registrants_returns_an_empty_list(): void
    {
        $event = EventInstance::factory()->create();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson($this->url($event))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', []);
    }
}