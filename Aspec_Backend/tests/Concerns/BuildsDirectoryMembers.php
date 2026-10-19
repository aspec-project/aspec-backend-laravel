<?php

namespace Tests\Concerns;

use App\Models\Location;
use App\Models\MemberProfile;
use App\Models\Sector;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Testing\TestResponse;

/**
 * Membros do diretório nos testes.
 *
 * O MemberProfileFactory escolhe setor, distrito e nomes aleatórios; aqui ficam fixos para a
 * pesquisa e a ordenação serem estáveis. Só nomes ASCII: o SQLite dos testes distingue acentos.
 */
trait BuildsDirectoryMembers
{
    protected const MEMBERS_URL = '/api/members';

    protected const NOT_FOUND_BODY = ['success' => false, 'message' => 'Membro não encontrado.'];

    /** Chaves de cada item da lista, exatamente as que o frontend valida. */
    protected const LIST_ITEM_KEYS = [
        'average_rating',
        'business_name',
        'description',
        'id',
        'location',
        'logo_url',
        'name',
        'reviews_count',
        'sector',
    ];

    /** Chaves do perfil público. */
    protected const DETAIL_KEYS = [
        'address',
        'average_rating',
        'business_hours',
        'business_name',
        'description',
        'id',
        'location',
        'logo_url',
        'members_only',
        'name',
        'portfolio',
        'reviews_count',
        'sector',
        'social_links',
        'website_url',
    ];

    /**
     * Membro com conta Active e perfil: o caso que aparece no diretório.
     */
    protected function listedMember(array $profile = []): MemberProfile
    {
        return $this->profileFor(User::factory(), $profile);
    }

    /**
     * Perfil para um utilizador construído com o estado pedido (pending, inactive, admin, ...).
     */
    protected function profileFor(UserFactory|User $user, array $profile = []): MemberProfile
    {
        $owner = $user instanceof User ? $user : $user->create();

        return MemberProfile::factory()->create([
            'user_id' => $owner->id,
            'sector_id' => $this->firstSector()->id,
            'location_id' => $this->firstLocation()->id,
            'name' => 'Membro Teste',
            'business_name' => 'Empresa Teste',
            'description' => 'Descricao generica.',
            'congregation' => 'Congregacao Teste',
            'role_in_congregation' => 'Diacono',
            'logo_path' => null,
            ...$profile,
        ]);
    }

    protected function firstSector(): Sector
    {
        return Sector::orderBy('name')->firstOrFail();
    }

    protected function secondSector(): Sector
    {
        return Sector::orderBy('name')->skip(1)->firstOrFail();
    }

    protected function firstLocation(): Location
    {
        return Location::orderBy('name')->firstOrFail();
    }

    protected function secondLocation(): Location
    {
        return Location::orderBy('name')->skip(1)->firstOrFail();
    }

    protected function listMembers(array $query = []): TestResponse
    {
        return $this->getJson(self::MEMBERS_URL.($query ? '?'.http_build_query($query) : ''));
    }

    protected function showMember(string $id): TestResponse
    {
        return $this->getJson(self::MEMBERS_URL.'/'.$id);
    }

    /**
     * @return list<string>
     */
    protected function listedIds(TestResponse $response): array
    {
        return array_column($response->json('data.items'), 'id');
    }

    /**
     * Todas as chaves do JSON, a qualquer profundidade.
     *
     * @return list<string>
     */
    protected function allJsonKeys(array $data): array
    {
        $keys = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }

            if (is_array($value)) {
                $keys = [...$keys, ...$this->allJsonKeys($value)];
            }
        }

        return array_values(array_unique($keys));
    }
}
