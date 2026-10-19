<?php

namespace Tests\Feature\Database;

use App\Models\Location;
use App\Models\MemberProfile;
use App\Models\User;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LocationSeederTest extends TestCase
{
    use RefreshDatabase;

    private const LOCATIONS = [
        'Açores',
        'Aveiro',
        'Beja',
        'Braga',
        'Bragança',
        'Castelo Branco',
        'Coimbra',
        'Évora',
        'Faro',
        'Guarda',
        'Leiria',
        'Lisboa',
        'Madeira',
        'Portalegre',
        'Porto',
        'Santarém',
        'Setúbal',
        'Viana do Castelo',
        'Vila Real',
        'Viseu',
    ];

    #[Test]
    public function seeder_creates_the_eighteen_districts_and_the_two_autonomous_regions(): void
    {
        $this->assertEqualsCanonicalizing(self::LOCATIONS, Location::pluck('name')->all());
        $this->assertSame(20, Location::count());
    }

    #[Test]
    public function running_the_seeder_again_does_not_duplicate_locations(): void
    {
        $this->seed(LocationSeeder::class);

        $this->assertSame(20, Location::count());
        $this->assertSame(1, Location::where('name', 'Açores')->count());
        $this->assertSame(1, Location::where('name', 'Madeira')->count());
    }

    #[Test]
    public function seeding_an_existing_database_adds_the_regions_and_keeps_existing_districts(): void
    {
        Location::whereIn('name', ['Açores', 'Madeira'])->delete();
        $ids = Location::pluck('id', 'name');
        $lisbonId = $ids['Lisboa'];
        $profile = MemberProfile::factory()->create([
            'user_id' => User::factory(),
            'location_id' => $lisbonId,
        ]);

        $this->seed(LocationSeeder::class);

        $this->assertTrue(Location::where('name', 'Açores')->exists());
        $this->assertTrue(Location::where('name', 'Madeira')->exists());
        $this->assertSame(20, Location::count());
        foreach ($ids as $name => $id) {
            $this->assertSame($id, Location::where('name', $name)->value('id'), "O id de {$name} mudou.");
        }
        $this->assertSame($lisbonId, $profile->fresh()->location_id);
    }
}
