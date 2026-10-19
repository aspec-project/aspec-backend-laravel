<?php

namespace Tests\Feature\Admin;

use App\Models\EventCategory;
use App\Models\EventInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminEventCategoryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/event-categories';

    private const SEEDED_NAMES = [
        'Almoço',
        'Formação',
        'Jantar',
        'Pequeno-almoço',
        'Workshop',
    ];

    private function category(string $name): EventCategory
    {
        return EventCategory::where('name', $name)->firstOrFail();
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function assertSeededCategoriesUnchanged(): void
    {
        $this->assertSame(5, EventCategory::count());
        $this->assertEqualsCanonicalizing(self::SEEDED_NAMES, EventCategory::pluck('name')->all());
    }

    #[Test]
    public function guest_cannot_use_admin_category_endpoints(): void
    {
        $formacao = $this->category('Formação');
        $workshop = $this->category('Workshop');

        $responses = [
            $this->getJson(self::URL),
            $this->postJson(self::URL, ['name' => 'Workshops']),
            $this->putJson(self::URL."/{$formacao->id}", ['name' => 'Outro nome']),
            $this->deleteJson(self::URL."/{$workshop->id}"),
        ];

        foreach ($responses as $response) {
            $response
                ->assertStatus(401)
                ->assertJson([
                    'success' => false,
                    'message' => 'Não autenticado.',
                ]);
        }

        $this->assertSeededCategoriesUnchanged();
    }

    #[Test]
    public function admin_can_list_categories_ordered_by_name(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson(self::URL);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Lista de categorias obtida com sucesso.')
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'color'],
                ],
            ]);

        $this->assertSame(self::SEEDED_NAMES, array_column($response->json('data'), 'name'));
    }

    #[Test]
    public function admin_can_create_a_category(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson(self::URL, [
            'name' => 'Workshops',
            'color' => '#00796B',
        ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Categoria criada com sucesso.')
            ->assertJsonPath('data.name', 'Workshops')
            ->assertJsonPath('data.color', '#00796B');

        $this->assertTrue(Str::isUuid($response->json('data.id')));

        $this->assertDatabaseHas('event_categories', [
            'id' => $response->json('data.id'),
            'name' => 'Workshops',
            'color' => '#00796B',
        ]);
    }

    #[Test]
    public function category_name_is_trimmed_and_blank_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, ['name' => '  Workshops  ', 'color' => '#00796B'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Workshops');

        $this->assertDatabaseHas('event_categories', ['name' => 'Workshops']);

        $this->postJson(self::URL, ['name' => '   ', 'color' => '#00796B'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'O campo nome é obrigatório.');
    }

    #[Test]
    public function creating_a_category_requires_a_name(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Os dados enviados são inválidos.')
            ->assertJsonPath('errors.name.0', 'O campo nome é obrigatório.');

        $this->assertSame(5, EventCategory::count());
    }

    #[Test]
    public function category_name_cannot_exceed_30_characters(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, ['name' => str_repeat('a', 31), 'color' => '#00796B'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'O campo nome não pode ter mais de 30 caracteres.');

        $this->postJson(self::URL, ['name' => str_repeat('a', 30), 'color' => '#00796B'])
            ->assertStatus(201);

        $this->postJson(self::URL, ['name' => str_repeat('ç', 30), 'color' => '#5D4037'])
            ->assertStatus(201);
    }

    #[Test]
    public function category_name_must_be_text(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, ['name' => 123])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'O campo nome tem de ser texto.');

        $this->postJson(self::URL, ['name' => ['a']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame(5, EventCategory::count());
    }

    #[Test]
    public function category_name_must_be_unique_ignoring_case(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, ['name' => 'Formação'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Já existe uma categoria com este nome.');

        $this->postJson(self::URL, ['name' => 'JANTAR'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Já existe uma categoria com este nome.');

        $this->assertSame(5, EventCategory::count());
    }

    #[Test]
    public function invalid_color_is_rejected(): void
    {
        $this->actingAsAdmin();

        foreach (['red', '#12345', '#GGGGGG', '#000000;background:red'] as $color) {
            $this->postJson(self::URL, ['name' => 'Workshops', 'color' => $color])
                ->assertStatus(422)
                ->assertJsonPath('errors.color.0', 'A cor tem de estar no formato #RRGGBB (ex.: #8A7043).');
        }

        $this->assertDatabaseMissing('event_categories', ['name' => 'Workshops']);
    }

    #[Test]
    public function color_is_required_on_create(): void
    {
        $this->actingAsAdmin();

        $requests = [
            ['name' => 'Workshops'],
            ['name' => 'Workshops', 'color' => null],
            ['name' => 'Workshops', 'color' => ''],
        ];

        foreach ($requests as $request) {
            $this->postJson(self::URL, $request)
                ->assertStatus(422)
                ->assertJsonPath('errors.color.0', 'O campo cor é obrigatório.');
        }

        $this->assertDatabaseMissing('event_categories', ['name' => 'Workshops']);
    }

    #[Test]
    public function create_ignores_fields_that_are_not_allowed(): void
    {
        $this->actingAsAdmin();

        $forgedId = '11111111-1111-4111-8111-111111111111';

        $response = $this->postJson(self::URL, [
            'name' => 'Workshops',
            'color' => '#00796B',
            'id' => $forgedId,
            'created_at' => '2000-01-01',
        ]);

        $response->assertStatus(201);

        $this->assertNotSame($forgedId, $response->json('data.id'));
        $this->assertDatabaseMissing('event_categories', ['id' => $forgedId]);

        $created = EventCategory::where('name', 'Workshops')->firstOrFail();
        $this->assertNotSame(2000, $created->created_at->year);
    }

    #[Test]
    public function admin_can_rename_a_category(): void
    {
        $this->actingAsAdmin();
        $workshop = $this->category('Workshop');

        $this->putJson(self::URL."/{$workshop->id}", ['name' => 'Workshop Prático', 'color' => '#C62828'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Categoria atualizada com sucesso.')
            ->assertJsonPath('data.id', $workshop->id)
            ->assertJsonPath('data.name', 'Workshop Prático');

        $this->assertDatabaseHas('event_categories', [
            'id' => $workshop->id,
            'name' => 'Workshop Prático',
        ]);
        $this->assertSame(5, EventCategory::count());
    }

    #[Test]
    public function updating_with_the_same_name_is_allowed(): void
    {
        $this->actingAsAdmin();
        $formacao = $this->category('Formação');

        $this->putJson(self::URL."/{$formacao->id}", ['name' => 'Formação', 'color' => '#1565C0'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Formação');

        $this->putJson(self::URL."/{$formacao->id}", ['name' => 'FORMAÇÃO', 'color' => '#1565C0'])
            ->assertOk()
            ->assertJsonPath('data.name', 'FORMAÇÃO');

        $this->assertDatabaseHas('event_categories', [
            'id' => $formacao->id,
            'name' => 'FORMAÇÃO',
        ]);
    }

    #[Test]
    public function cannot_rename_to_an_existing_name(): void
    {
        $this->actingAsAdmin();
        $workshop = $this->category('Workshop');

        $this->putJson(self::URL."/{$workshop->id}", ['name' => 'formação'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Já existe uma categoria com este nome.');

        $this->assertSeededCategoriesUnchanged();
    }

    #[Test]
    public function update_requires_a_name(): void
    {
        $this->actingAsAdmin();
        $formacao = $this->category('Formação');

        $this->putJson(self::URL."/{$formacao->id}", ['color' => '#000000'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'O campo nome é obrigatório.');

        $this->assertDatabaseHas('event_categories', [
            'id' => $formacao->id,
            'color' => '#1565C0',
        ]);
    }

    #[Test]
    public function update_requires_a_color(): void
    {
        $this->actingAsAdmin();
        $formacao = $this->category('Formação');

        $this->putJson(self::URL."/{$formacao->id}", ['name' => 'Formação'])
            ->assertStatus(422)
            ->assertJsonPath('errors.color.0', 'O campo cor é obrigatório.');

        $this->putJson(self::URL."/{$formacao->id}", ['name' => 'Formação', 'color' => null])
            ->assertStatus(422)
            ->assertJsonPath('errors.color.0', 'O campo cor é obrigatório.');

        $this->assertDatabaseHas('event_categories', [
            'id' => $formacao->id,
            'color' => '#1565C0',
        ]);
    }

    #[Test]
    public function updating_a_missing_category_returns_404(): void
    {
        $this->actingAsAdmin();

        foreach (['00000000-0000-0000-0000-000000000000', 'abc'] as $id) {
            $this->putJson(self::URL."/{$id}", ['name' => 'X'])
                ->assertNotFound()
                ->assertJson([
                    'success' => false,
                    'message' => 'Recurso não encontrado.',
                ]);
        }

        $this->assertSeededCategoriesUnchanged();
    }

    #[Test]
    public function admin_can_delete_a_category_without_events(): void
    {
        $this->actingAsAdmin();
        $workshop = $this->category('Workshop');

        $this->deleteJson(self::URL."/{$workshop->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Categoria eliminada com sucesso.')
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('event_categories', ['id' => $workshop->id]);
        $this->assertSame(4, EventCategory::count());
    }

    #[Test]
    public function cannot_delete_a_category_with_events(): void
    {
        $this->actingAsAdmin();
        $formacao = $this->category('Formação');
        $event = EventInstance::factory()->create(['category_id' => $formacao->id]);

        $this->deleteJson(self::URL."/{$formacao->id}")
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Não é possível eliminar uma categoria com eventos associados.',
            ]);

        $this->assertDatabaseHas('event_categories', ['id' => $formacao->id]);
        $this->assertDatabaseHas('event_instances', ['id' => $event->id]);
    }

    #[Test]
    public function deleting_a_missing_category_returns_404(): void
    {
        $this->actingAsAdmin();

        foreach (['00000000-0000-0000-0000-000000000000', 'abc'] as $id) {
            $this->deleteJson(self::URL."/{$id}")
                ->assertNotFound()
                ->assertJson([
                    'success' => false,
                    'message' => 'Recurso não encontrado.',
                ]);
        }

        $this->assertSeededCategoriesUnchanged();
    }

    #[Test]
    public function member_cannot_use_admin_category_endpoints(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $formacao = $this->category('Formação');
        $workshop = $this->category('Workshop');

        $responses = [
            $this->getJson(self::URL),
            $this->postJson(self::URL, ['name' => 'X']),
            $this->putJson(self::URL."/{$formacao->id}", ['name' => 'X']),
            $this->deleteJson(self::URL."/{$workshop->id}"),
        ];

        foreach ($responses as $response) {
            $response
                ->assertForbidden()
                ->assertJson([
                    'success' => false,
                    'message' => 'Não tem permissão para realizar esta ação.',
                ]);
        }

        $this->assertSeededCategoriesUnchanged();
    }

    #[Test]
    public function inactive_admin_cannot_use_admin_category_endpoints(): void
    {
        Sanctum::actingAs(User::factory()->admin()->inactive()->create());
        $workshop = $this->category('Workshop');

        $responses = [
            $this->postJson(self::URL, ['name' => 'X']),
            $this->deleteJson(self::URL."/{$workshop->id}"),
        ];

        foreach ($responses as $response) {
            $response
                ->assertForbidden()
                ->assertJson([
                    'success' => false,
                    'message' => 'A conta não está ativa e não pode editar dados.',
                ]);
        }

        $this->assertSeededCategoriesUnchanged();
    }

    #[Test]
    public function color_must_be_unique_ignoring_case(): void
    {
        $this->actingAsAdmin();

        foreach (['#1565c0', '#1565C0'] as $color) {
            $this->postJson(self::URL, ['name' => 'Workshops', 'color' => $color])
                ->assertStatus(422)
                ->assertJsonPath('errors.color.0', 'Já existe uma categoria com esta cor.');
        }

        $this->assertDatabaseMissing('event_categories', ['name' => 'Workshops']);
        $this->assertSame(5, EventCategory::count());
    }

    #[Test]
    public function color_is_stored_in_uppercase(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, ['name' => 'Workshops', 'color' => '#00796b'])
            ->assertStatus(201)
            ->assertJsonPath('data.color', '#00796B');

        $this->assertDatabaseHas('event_categories', [
            'name' => 'Workshops',
            'color' => '#00796B',
        ]);
    }

    #[Test]
    public function updating_keeps_its_own_color_in_any_case(): void
    {
        $this->actingAsAdmin();
        $formacao = $this->category('Formação');

        $this->putJson(self::URL."/{$formacao->id}", ['name' => 'Formação', 'color' => '#1565c0'])
            ->assertOk()
            ->assertJsonPath('data.color', '#1565C0');
    }

    #[Test]
    public function cannot_update_to_another_categorys_color(): void
    {
        $this->actingAsAdmin();
        $formacao = $this->category('Formação');

        $this->putJson(self::URL."/{$formacao->id}", ['name' => 'Formação', 'color' => '#2e7d32'])
            ->assertStatus(422)
            ->assertJsonPath('errors.color.0', 'Já existe uma categoria com esta cor.');

        $this->assertDatabaseHas('event_categories', [
            'id' => $formacao->id,
            'color' => '#1565C0',
        ]);
    }

    #[Test]
    public function admin_list_includes_the_number_of_events_per_category(): void
    {
        $this->actingAsAdmin();
        $formacao = $this->category('Formação');
        $workshop = $this->category('Workshop');

        EventInstance::factory()->create(['category_id' => $formacao->id]);
        EventInstance::factory()->create([
            'category_id' => $formacao->id,
            'event_date' => today()->subDays(10),
            'registration_deadline' => today()->subDays(11),
        ]);
        EventInstance::factory()->create(['category_id' => $workshop->id]);

        $response = $this->getJson(self::URL);

        $response
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'color', 'events_count'],
                ],
            ]);

        $counts = array_column($response->json('data'), 'events_count', 'name');

        $this->assertSame(2, $counts['Formação']);
        $this->assertSame(1, $counts['Workshop']);
        $this->assertSame(0, $counts['Jantar']);
        $this->assertSame(0, $counts['Pequeno-almoço']);
        $this->assertSame(0, $counts['Almoço']);
    }

    #[Test]
    public function create_and_update_responses_include_the_number_of_events(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, ['name' => 'Workshops', 'color' => '#00796B'])
            ->assertStatus(201)
            ->assertJsonPath('data.events_count', 0);

        $formacao = $this->category('Formação');
        EventInstance::factory()->create(['category_id' => $formacao->id]);

        $this->putJson(self::URL."/{$formacao->id}", ['name' => 'Formação', 'color' => '#1565C0'])
            ->assertOk()
            ->assertJsonPath('data.events_count', 1);
    }
}
