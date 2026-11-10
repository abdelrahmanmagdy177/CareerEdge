<?php

namespace App\Modules\Profiles\Database\Factories;

use App\Modules\Profiles\Models\Specialization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Specialization>
 */
class SpecializationFactory extends Factory
{
    protected $model = Specialization::class;

    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 999999);

        return [
            'slug' => 'specialization-'.$number,
            'name_en' => 'Specialization '.$number,
            'name_ar' => 'تخصص '.$number,
            'parent_id' => null,
        ];
    }
}
