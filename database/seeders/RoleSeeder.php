<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Seed the application's default care roles.
     */
    public function run(): void
    {
        foreach (['Admin', 'PDL', 'Pflegekraft'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}
