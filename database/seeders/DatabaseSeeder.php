<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's reference data.
     */
    public function run(): void
    {
        $this->call([
            CurrencySeeder::class,
        ]);
    }
}
