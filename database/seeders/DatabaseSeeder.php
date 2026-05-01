<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Resident;
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

        $secondLocation = Location::firstOrCreate(
            ['name' => 'Wohnbereich B'],
            [
                'short_name' => 'B',
                'description' => 'Zweiter Beispiel-Wohnbereich für Tests mit bereichsgetrennter Sichtbarkeit.',
                'active' => true,
            ],
        );

        Resident::firstOrCreate(
            [
                'location_id' => $location->id,
                'first_name' => 'Erika',
                'last_name' => 'Mustermann',
            ],
            [
                'birth_date' => '1938-05-12',
                'room_number' => 'A-101',
                'care_level' => 3,
                'active' => true,
            ],
        );

        Resident::firstOrCreate(
            [
                'location_id' => $secondLocation->id,
                'first_name' => 'Karl',
                'last_name' => 'Beispiel',
            ],
            [
                'birth_date' => '1941-09-24',
                'room_number' => 'B-201',
                'care_level' => 2,
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

        $pdl = User::updateOrCreate(
            ['email' => 'pdl@pflegedex.local'],
            [
                'location_id' => $location->id,
                'name' => 'Pflegedex PDL',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $pdl->assignRole('PDL');
        $pdl->locations()->syncWithoutDetaching([$location->id, $secondLocation->id]);

        $carl = User::updateOrCreate(
            ['email' => 'carl@pflegedex.local'],
            [
                'location_id' => $location->id,
                'name' => 'Carl Pflegekraft',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $carl->syncRoles(['Pflegekraft']);
        $carl->locations()->sync([$location->id]);
    }
}
