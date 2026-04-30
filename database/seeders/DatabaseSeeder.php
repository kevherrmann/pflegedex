<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

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

        $admin = User::updateOrCreate(
            ['email' => 'admin@pflegedex.local'],
            [
                'location_id' => $location->id,
                'name' => 'Pflegedex Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $admin->assignRole('Admin');
    }
}
