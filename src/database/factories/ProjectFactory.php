<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'project_code' => strtoupper(fake()->bothify('PJ-#####')),
            'project_name' => fake()->company().' Project',
            'status' => 'active',
            'billable_flag' => fake()->boolean(),
            'description' => fake()->sentence(),
        ];
    }
}
