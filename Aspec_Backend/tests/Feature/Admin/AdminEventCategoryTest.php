<?php

namespace Tests\Feature\Admin;

use App\Models\EventCategory;
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
        'Formações',
        'Jantares de Networking',
        'Pequenos-almoços de Negócios',
        'Reuniões',
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
        $this->assertSame(4, EventCategory::count());
        $this->assertEqualsCanonicalizing(self::SEEDED_NAMES, EventCategory::pluck('name')->all());
    }

    #[Test]
    public function guest_cannot_use_admin_category_endpoints(): void
    {
        $formacoes = $this->category('Formações');
        $reunioes = $this->category('Reuniões');

        $responses = [
            $this->getJson(self::URL),
            $this->postJson(self::URL, ['name' => 'Workshops']),
            $this->putJson(self::URL."/{$formacoes->id}", ['name' => 'Outro nome']),
            $this->deleteJson(self::URL."/{$reunioes->id}"),
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
            ->assertJsonCount(4, 'data')
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
            'color' => '#315A79',
        ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Categoria criada com sucesso.')
            ->assertJsonPath('data.name', 'Workshops')
            ->assertJsonPath('data.color', '#315A79');

        $this->assertTrue(Str::isUuid($response->json('data.id')));

        $this->assertDatabaseHas('event_categories', [
            'id' => $response->json('data.id'),
            'name' => 'Workshops',
            'color' => '#315A79',
        ]);
    }

    #[Test]
    public function category_name_is_trimmed_and_blank_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, ['name' => '  Workshops  '])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Workshops');

        $this->assertDatabaseHas('event_categories', ['name' => 'Workshops']);

        $this->postJson(self::URL, ['name' => '   '])
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

        $this->assertSame(4, EventCategory::count());
    }

    #[Test]
    public function category_name_cannot_exceed_100_characters(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, ['name' => str_repeat('a', 101)])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'O campo nome não pode ter mais de 100 caracteres.');

        $this->postJson(self::URL, ['name' => str_repeat('a', 100)])
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

        $this->assertSame(4, EventCategory::count());
    }

    #[Test]
    public function category_name_must_be_unique_ignoring_case(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, ['name' => 'Formações'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Já existe uma categoria com este nome.');

        $this->postJson(self::URL, ['name' => 'JANTARES DE NETWORKING'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Já existe uma categoria com este nome.');

        $this->assertSame(4, EventCategory::count());
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
    public function color_is_optional_on_create(): void
    {
        $this->actingAsAdmin();

        $this->postJson(self::URL, ['name' => 'Workshops'])
            ->assertStatus(201)
            ->assertJsonPath('data.color', null);

        $this->postJson(self::URL, ['name' => 'Webinars', 'color' => null])
            ->assertStatus(201)
            ->assertJsonPath('data.color', null);

        $this->assertDatabaseHas('event_categories', ['name' => 'Workshops', 'color' => null]);
        $this->assertDatabaseHas('event_categories', ['name' => 'Webinars', 'color' => null]);
    }

    #[Test]
    public function create_ignores_fields_that_are_not_allowed(): void
    {
        $this->actingAsAdmin();

        $forgedId = '11111111-1111-4111-8111-111111111111';

        $response = $this->postJson(self::URL, [
            'name' => 'Workshops',
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
        $reunioes = $this->category('Reuniões');

        $this->putJson(self::URL."/{$reunioes->id}", ['name' => 'Reuniões de Núcleo'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Categoria atualizada com sucesso.')
            ->assertJsonPath('data.id', $reunioes->id)
            ->assertJsonPath('data.name', 'Reuniões de Núcleo');

        $this->assertDatabaseHas('event_categories', [
            'id' => $reunioes->id,
            'name' => 'Reuniões de Núcleo',
        ]);
        $this->assertSame(4, EventCategory::count());
    }

    #[Test]
    public function updating_with_the_same_name_is_allowed(): void
    {
        $this->actingAsAdmin();
        $formacoes = $this->category('Formações');

        $this->putJson(self::URL."/{$formacoes->id}", ['name' => 'Formações'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Formações');

        $this->putJson(self::URL."/{$formacoes->id}", ['name' => 'FORMAÇÕES'])
            ->assertOk()
            ->assertJsonPath('data.name', 'FORMAÇÕES');

        $this->assertDatabaseHas('event_categories', [
            'id' => $formacoes->id,
            'name' => 'FORMAÇÕES',
        ]);
    }

    #[Test]
    public function cannot_rename_to_an_existing_name(): void
    {
        $this->actingAsAdmin();
        $reunioes = $this->category('Reuniões');

        $this->putJson(self::URL."/{$reunioes->id}", ['name' => 'formações'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Já existe uma categoria com este nome.');

        $this->assertSeededCategoriesUnchanged();
    }

    #[Test]
    public function update_requires_a_name(): void
    {
        $this->actingAsAdmin();
        $formacoes = $this->category('Formações');

        $this->putJson(self::URL."/{$formacoes->id}", ['color' => '#000000'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'O campo nome é obrigatório.');

        $this->assertDatabaseHas('event_categories', [
            'id' => $formacoes->id,
            'color' => '#315A79',
        ]);
    }

    #[Test]
    public function color_is_kept_when_absent_and_cleared_when_null(): void
    {
        $this->actingAsAdmin();
        $formacoes = $this->category('Formações');
        $this->assertSame('#315A79', $formacoes->color);

        $this->putJson(self::URL."/{$formacoes->id}", ['name' => 'Formações'])
            ->assertOk()
            ->assertJsonPath('data.color', '#315A79');

        $this->putJson(self::URL."/{$formacoes->id}", ['name' => 'Formações', 'color' => null])
            ->assertOk()
            ->assertJsonPath('data.color', null);

        $this->assertDatabaseHas('event_categories', [
            'id' => $formacoes->id,
            'color' => null,
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
        $reunioes = $this->category('Reuniões');

        $this->deleteJson(self::URL."/{$reunioes->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Categoria eliminada com sucesso.')
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('event_categories', ['id' => $reunioes->id]);
        $this->assertSame(3, EventCategory::count());
    }

    #[Test]
    public function cannot_delete_a_category_with_events(): void
    {
        $this->markTestSkipped('À espera da tabela event_instances (ASPEC-195, Guilherme).');

        $this->actingAsAdmin();
        $formacoes = $this->category('Formações');
        $event = \App\Models\EventInstance::factory()->create(['category_id' => $formacoes->id]);

        $this->deleteJson(self::URL."/{$formacoes->id}")
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Não é possível eliminar uma categoria com eventos associados.',
            ]);

        $this->assertDatabaseHas('event_categories', ['id' => $formacoes->id]);
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
        $formacoes = $this->category('Formações');
        $reunioes = $this->category('Reuniões');

        $responses = [
            $this->getJson(self::URL),
            $this->postJson(self::URL, ['name' => 'X']),
            $this->putJson(self::URL."/{$formacoes->id}", ['name' => 'X']),
            $this->deleteJson(self::URL."/{$reunioes->id}"),
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
        $reunioes = $this->category('Reuniões');

        $responses = [
            $this->postJson(self::URL, ['name' => 'X']),
            $this->deleteJson(self::URL."/{$reunioes->id}"),
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
}
