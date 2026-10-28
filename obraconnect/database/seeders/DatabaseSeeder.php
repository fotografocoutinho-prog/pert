<?php

namespace Database\Seeders;

use App\Access\PermissionSynchronizer;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Dados base necessários em qualquer instalação. Dados de demonstração só em local.
     */
    public function run(PermissionSynchronizer $permissions): void
    {
        $permissions->sync();
        $this->call(PlanSeeder::class);

        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
