<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Cria só a linha da empresa. Para uma empresa completa (perfis, administrador,
 * subscrição) usar App\Actions\Tenancy\RegisterCompany.
 *
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'country_code' => 'PT',
        ];
    }
}
