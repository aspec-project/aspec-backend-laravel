<?php

namespace Tests\Feature\Admin;

use App\Models\EventCategory;
use App\Models\EventInstance;
use App\Models\EventSeries;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Sessão X/Y" no backoffice: cada evento do admin traz series {position, total}, com a posição
 * global na série (por data, hora e id, contando as sessões passadas) e o total de sessões; null nos
 * eventos avulsos. As rotas públicas não têm a chave.
 */
class AdminEventSeriesPositionTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/events';

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function day(int $offset): Carbon
    {
        return now(config('app.event_timezone', 'Europe/Lisbon'))->startOfDay()->addDays($offset);
    }

    private function newSeries(): EventSeries
    {
        return EventSeries::create([
            'frequency' => 'weekly',
            'ends_on' => $this->day(60)->toDateString(),
            'registration_deadline_days_before' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function seriesSession(?EventSeries $series, int $dayOffset, string $startTime = '10:00:00', array $attributes = []): EventInstance
    {
        $date = $this->day($dayOffset);

        return EventInstance::factory()->create([
            'series_id' => $series?->id,
            'event_date' => $date->toDateString(),
            'event_start_time' => $startTime,
            'event_end_time' => '23:00:00',
            'registration_deadline' => $date->copy()->subDay()->setTime(23, 59)->format('Y-m-d H:i:s'),
            ...$attributes,
        ]);
    }

    /**
     * Itens do índice do admin indexados pelo id.
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexItems(string $query = ''): array
    {
        $items = $this->getJson(self::URL.'?per_page=50'.$query)
            ->assertOk()
            ->json('data.items');

        return collect($items)->keyBy('id')->all();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function assertSeries(?array $expected, array $item): void
    {
        $this->assertArrayHasKey('series', $item);
        $this->assertSame($expected, $item['series']);
    }

    /**
     * Pedido de criação/edição válido com a data e as horas indicadas.
     *
     * @return array<string, mixed>
     */
    private function payload(Carbon $date, string $startTime = '10:00'): array
    {
        return [
            'category_id' => EventCategory::query()->firstOrFail()->id,
            'location_id' => Location::query()->firstOrFail()->id,
            'name' => 'Sessão de formação',
            'event_date' => $date->toDateString(),
            'event_start_time' => $startTime,
            'event_end_time' => '23:00',
            'description' => 'Descrição da sessão.',
            'address' => 'Rua de teste, 10',
            'latitude' => 38.7223,
            'longitude' => -9.1393,
            'max_attendees' => 30,
            'registration_deadline' => $date->copy()->subDay()->toDateString(),
            'is_online' => false,
        ];
    }

    #[Test]
    public function index_returns_the_position_and_total_of_each_session_and_null_for_one_off_events(): void
    {
        $series = $this->newSeries();
        $first = $this->seriesSession($series, 10);
        $second = $this->seriesSession($series, 17);
        $third = $this->seriesSession($series, 24);
        $oneOff = $this->seriesSession(null, 12);

        $items = $this->indexItems();

        $this->assertSeries(['position' => 1, 'total' => 3], $items[$first->id]);
        $this->assertSeries(['position' => 2, 'total' => 3], $items[$second->id]);
        $this->assertSeries(['position' => 3, 'total' => 3], $items[$third->id]);
        $this->assertSeries(null, $items[$oneOff->id]);
    }

    #[Test]
    public function sessions_on_the_same_date_are_ordered_by_start_time(): void
    {
        $series = $this->newSeries();
        $evening = $this->seriesSession($series, 10, '18:00:00');
        $morning = $this->seriesSession($series, 10, '09:00:00');

        $items = $this->indexItems();

        $this->assertSeries(['position' => 1, 'total' => 2], $items[$morning->id]);
        $this->assertSeries(['position' => 2, 'total' => 2], $items[$evening->id]);
    }

    #[Test]
    public function sessions_on_the_same_date_and_time_are_ordered_by_id(): void
    {
        $series = $this->newSeries();
        $higherId = $this->seriesSession($series, 10, '10:00:00', ['id' => 'ffffffff-ffff-4fff-bfff-ffffffffffff']);
        $lowerId = $this->seriesSession($series, 10, '10:00:00', ['id' => '00000000-0000-4000-8000-000000000001']);

        $items = $this->indexItems();

        $this->assertSeries(['position' => 1, 'total' => 2], $items[$lowerId->id]);
        $this->assertSeries(['position' => 2, 'total' => 2], $items[$higherId->id]);
    }

    #[Test]
    public function past_sessions_count_for_the_position_in_the_upcoming_tab(): void
    {
        $series = $this->newSeries();
        $past = $this->seriesSession($series, -7);
        $second = $this->seriesSession($series, 7);
        $third = $this->seriesSession($series, 14);

        $items = $this->indexItems('&period=upcoming');

        $this->assertArrayNotHasKey($past->id, $items);
        $this->assertSeries(['position' => 2, 'total' => 3], $items[$second->id]);
        $this->assertSeries(['position' => 3, 'total' => 3], $items[$third->id]);
    }

    #[Test]
    public function deleting_a_session_renumbers_the_following_ones(): void
    {
        $series = $this->newSeries();
        $first = $this->seriesSession($series, 10);
        $second = $this->seriesSession($series, 17);
        $third = $this->seriesSession($series, 24);

        $this->deleteJson(self::URL."/{$second->id}")->assertOk();

        $items = $this->indexItems();

        $this->assertSeries(['position' => 1, 'total' => 2], $items[$first->id]);
        $this->assertSeries(['position' => 2, 'total' => 2], $items[$third->id]);
    }

    #[Test]
    public function show_returns_the_series_position(): void
    {
        $series = $this->newSeries();
        $this->seriesSession($series, 10);
        $second = $this->seriesSession($series, 17);
        $this->seriesSession($series, 24);

        $data = $this->getJson(self::URL."/{$second->id}")->assertOk()->json('data');

        $this->assertSeries(['position' => 2, 'total' => 3], $data);
    }

    #[Test]
    public function show_returns_null_series_for_a_one_off_event(): void
    {
        $oneOff = $this->seriesSession(null, 10);

        $data = $this->getJson(self::URL."/{$oneOff->id}")->assertOk()->json('data');

        $this->assertSeries(null, $data);
    }

    #[Test]
    public function update_returns_the_series_position(): void
    {
        $series = $this->newSeries();
        $this->seriesSession($series, 10);
        $second = $this->seriesSession($series, 17);
        $this->seriesSession($series, 24);

        $data = $this->putJson(self::URL."/{$second->id}", $this->payload($this->day(17)))
            ->assertOk()
            ->json('data');

        $this->assertSeries(['position' => 2, 'total' => 3], $data);
    }

    #[Test]
    public function storing_a_one_off_event_returns_null_series(): void
    {
        $data = $this->postJson(self::URL, $this->payload($this->day(10)))
            ->assertCreated()
            ->json('data');

        $this->assertSeries(null, $data);
    }

    #[Test]
    public function storing_a_series_returns_the_position_of_each_session(): void
    {
        $payload = $this->payload($this->day(10));
        unset($payload['registration_deadline']);
        $payload['recurrence'] = [
            'frequency' => 'weekly',
            'ends_on' => $this->day(24)->toDateString(),
            'registration_deadline_days_before' => 1,
        ];

        $sessions = $this->postJson(self::URL, $payload)
            ->assertCreated()
            ->json('data.sessions');

        $this->assertCount(3, $sessions);
        foreach ($sessions as $index => $session) {
            $this->assertSeries(['position' => $index + 1, 'total' => 3], $session);
        }
    }

    #[Test]
    public function public_event_routes_do_not_include_the_series_position(): void
    {
        $series = $this->newSeries();
        $session = $this->seriesSession($series, 10);
        $this->seriesSession($series, 17);

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $session->id)
            ->assertJsonMissingPath('data.items.0.series');

        $this->getJson("/api/events/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.series_id', $series->id)
            ->assertJsonMissingPath('data.series');
    }

    #[Test]
    public function index_runs_the_same_number_of_queries_for_few_and_many_series_sessions(): void
    {
        $this->getJson(self::URL)->assertOk();

        $counts = [];
        foreach ([2, 6] as $total) {
            EventInstance::query()->delete();
            foreach (range(1, $total) as $offset) {
                $series = $this->newSeries();
                $this->seriesSession($series, $offset);
                $this->seriesSession($series, $offset + 30);
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::URL.'?per_page=50')->assertOk()->assertJsonCount($total * 2, 'data.items');
            $counts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        $this->assertSame($counts[0], $counts[1]);
    }
}
