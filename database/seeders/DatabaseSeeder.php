<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Client::query()->firstOrCreate([
            'name' => 'Demo Client',
        ], [
            'external_reference' => 'demo-client',
        ]);
    }
}
