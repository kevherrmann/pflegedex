<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        $location = Location::firstOrCreate(
            ['name' => 'Wohnbereich A'],
            [
                'short_name' => 'A',
                'description' => 'Erster Beispiel-Wohnbereich für die lokale Entwicklung.',
                'active' => true,
            ],
        );

        $admin = User::factory()->create([
            'location_id' => $location->id,
            'name' => 'Pflegedex Admin',
            'email' => 'admin@pflegedex.local',
        ]);

        $admin->assignRole('Admin');
    }
}
