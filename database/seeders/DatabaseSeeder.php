<?php

namespace Database\Seeders;

use App\Modules\Profiles\Database\Seeders\SpecializationSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data the app cannot work without. Every seeder listed here is safe to re-run.
     */
    public function run(): void
    {
        $this->call([
            SpecializationSeeder::class,
        ]);
    }
}
