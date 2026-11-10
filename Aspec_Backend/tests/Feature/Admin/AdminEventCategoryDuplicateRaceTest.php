<?php

namespace Tests\Feature\Admin;

use App\Models\EventCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dois admins a criar ou editar categorias ao mesmo tempo passam ambos a validação e o índice unique
 * recusa o segundo. A corrida é simulada de forma determinista: o listener do modelo insere a linha
 * do "outro admin" mesmo antes do INSERT/UPDATE do pedido. O segundo admin recebe o mesmo 422 da
 * validação normal, no campo certo, e não um 500.
 */
class AdminEventCategoryDuplicateRaceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/event-categories';

    private const NAME_TAKEN = 'Já existe uma categoria com este nome.';

    private const COLOR_TAKEN = 'Já existe uma categoria com esta cor.';

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    /**
     * Insere uma vez, diretamente na tabela, a categoria criada pelo outro admin.
     *
     * @return callable(): void
     */
    private function otherAdminInserts(string $name, string $color): callable
    {
        $inserted = false;

        return function () use (&$inserted, $name, $color): void {
            if ($inserted) {
                return;
            }
            $inserted = true;

            DB::table('event_categories')->insert([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'color' => $color,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };
    }

    private function countNamed(string $name): int
    {
        return EventCategory::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->count();
    }

    private function countWithColor(string $color): int
    {
        return EventCategory::query()->where('color', $color)->count();
    }

    #[Test]
    public function creating_a_category_whose_name_was_just_taken_returns_422_on_name(): void
    {
        EventCategory::creating($this->otherAdminInserts('Networking', '#0A4251'));

        $this->postJson(self::URL, ['name' => 'Networking', 'color' => '#2A6E9E'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.name.0', self::NAME_TAKEN)
            ->assertJsonMissingPath('errors.color');

        $this->assertSame(1, $this->countNamed('Networking'));
        $this->assertSame(0, $this->countWithColor('#2A6E9E'));
    }

    #[Test]
    public function creating_a_category_whose_color_was_just_taken_returns_422_on_color(): void
    {
        EventCategory::creating($this->otherAdminInserts('Mentoria', '#2A6E9E'));

        $this->postJson(self::URL, ['name' => 'Networking', 'color' => '#2A6E9E'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.color.0', self::COLOR_TAKEN)
            ->assertJsonMissingPath('errors.name');

        $this->assertSame(1, $this->countWithColor('#2A6E9E'));
        $this->assertSame(0, $this->countNamed('Networking'));
    }

    #[Test]
    public function renaming_a_category_to_a_name_just_taken_returns_422_on_name(): void
    {
        $formacao = EventCategory::where('name', 'Formação')->firstOrFail();
        EventCategory::updating($this->otherAdminInserts('Formação Avançada', '#0A4251'));

        $this->putJson(self::URL."/{$formacao->id}", ['name' => 'Formação Avançada', 'color' => $formacao->color])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.name.0', self::NAME_TAKEN)
            ->assertJsonMissingPath('errors.color');

        $this->assertSame(1, $this->countNamed('Formação Avançada'));
        $this->assertSame('Formação', $formacao->fresh()->name);
    }

    #[Test]
    public function changing_a_category_to_a_color_just_taken_returns_422_on_color(): void
    {
        $formacao = EventCategory::where('name', 'Formação')->firstOrFail();
        $originalColor = $formacao->color;
        EventCategory::updating($this->otherAdminInserts('Mentoria', '#2A6E9E'));

        $this->putJson(self::URL."/{$formacao->id}", ['name' => 'Formação', 'color' => '#2A6E9E'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.color.0', self::COLOR_TAKEN)
            ->assertJsonMissingPath('errors.name');

        $this->assertSame(1, $this->countWithColor('#2A6E9E'));
        $this->assertSame($originalColor, $formacao->fresh()->color);
    }
}
