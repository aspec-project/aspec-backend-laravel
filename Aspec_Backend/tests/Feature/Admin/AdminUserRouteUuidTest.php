<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * As ações do admin sobre utilizadores só aceitam um UUID no id, como o show: um id inválido é
 * recusado pela rota com 404, antes do controller e sem chegar à base de dados.
 */
class AdminUserRouteUuidTest extends TestCase
{
    use RefreshDatabase;

    private const INVALID_ID = 'not-a-uuid';

    /**
     * @return array<string, array{0: string}>
     */
    public static function actions(): array
    {
        return [
            'approve' => ['approve'],
            'reject' => ['reject'],
            'block' => ['block'],
            'unblock' => ['unblock'],
            'promote' => ['promote'],
            'demote' => ['demote'],
        ];
    }

    #[Test]
    #[DataProvider('actions')]
    public function action_with_an_invalid_id_returns_404_without_querying_the_id(string $action): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $bindings = [];
        DB::listen(function (QueryExecuted $query) use (&$bindings) {
            array_push($bindings, ...$query->bindings);
        });

        $this->patchJson('/api/admin/users/'.self::INVALID_ID."/{$action}")
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Recurso não encontrado.',
            ]);

        $this->assertNotContains(self::INVALID_ID, $bindings);
    }
}
