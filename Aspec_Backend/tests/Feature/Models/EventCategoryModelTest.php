<?php

namespace Tests\Feature\Models;

use App\Models\EventCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventCategoryModelTest extends TestCase
{
    use RefreshDatabase;

    // T29 — uma categoria sem eventos não tem eventos associados.
    #[Test]
    public function category_has_no_events_when_event_instances_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('event_instances'));

        $category = EventCategory::factory()->create();

        $this->assertFalse($category->hasEvents());
    }

    // T30 — a factory gera dados que a validação da API (ASPEC-194) aceita.
    #[Test]
    public function factory_creates_a_valid_category(): void
    {
        $category = EventCategory::factory()->create();

        $this->assertTrue(Str::isUuid($category->id));
        $this->assertNotEmpty($category->name);
        $this->assertLessThanOrEqual(100, mb_strlen($category->name));
        $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $category->color);
        // 4 categorias do seeder + 1 criada pela factory.
        $this->assertSame(5, EventCategory::count());
    }
}
