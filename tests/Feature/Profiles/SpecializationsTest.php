<?php

namespace Tests\Feature\Profiles;

use App\Modules\Profiles\Database\Seeders\SpecializationSeeder;
use App\Modules\Profiles\Models\Specialization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpecializationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_is_public_and_has_the_contract_shape(): void
    {
        $this->seed(SpecializationSeeder::class);

        $response = $this->api('GET', '/specializations')->assertOk();

        $this->assertCount(24, $response->json());
        $this->assertSame(['id', 'slug', 'name', 'parentId'], array_keys($response->json(0)));
    }

    public function test_parents_come_first_and_children_point_at_their_parent(): void
    {
        $this->seed(SpecializationSeeder::class);

        $list = collect($this->api('GET', '/specializations')->json());

        $parents = $list->takeWhile(fn (array $item) => $item['parentId'] === null);
        $this->assertCount(9, $parents);
        $this->assertTrue($list->skip(9)->every(fn (array $item) => $item['parentId'] !== null));

        $backend = $list->firstWhere('slug', 'backend');
        $this->assertSame($list->firstWhere('slug', 'software-engineering')['id'], $backend['parentId']);
    }

    public function test_names_follow_the_accept_language_header_and_default_to_english(): void
    {
        $this->seed(SpecializationSeeder::class);
        $nameOfBackend = fn (array $headers) => collect(
            $this->api('GET', '/specializations', headers: $headers)->json(),
        )->firstWhere('slug', 'backend')['name'];

        $this->assertSame('Backend Development', $nameOfBackend([]));
        $this->assertSame('تطوير الواجهة الخلفية', $nameOfBackend(['Accept-Language' => 'ar']));
        $this->assertSame('تطوير الواجهة الخلفية', $nameOfBackend(['Accept-Language' => 'ar-EG,ar;q=0.9,en;q=0.5']));
        // A language we do not have falls back to English.
        $this->assertSame('Backend Development', $nameOfBackend(['Accept-Language' => 'fr']));
    }

    public function test_running_the_seeder_again_changes_nothing(): void
    {
        $this->seed(SpecializationSeeder::class);
        $idsBefore = Specialization::orderBy('slug')->pluck('id', 'slug')->all();

        $this->seed(SpecializationSeeder::class);

        $this->assertSame($idsBefore, Specialization::orderBy('slug')->pluck('id', 'slug')->all());
    }
}
