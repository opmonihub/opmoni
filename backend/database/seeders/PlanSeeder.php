<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Plan::upsert([
            ['slug' => 'basico', 'name' => 'Básico', 'limits' => json_encode(['users' => 5, 'clients' => 50])],
            ['slug' => 'profissional', 'name' => 'Profissional', 'limits' => json_encode(['users' => 20, 'clients' => 500])],
            ['slug' => 'empresarial', 'name' => 'Empresarial', 'limits' => json_encode([])],
        ], 'slug');
    }
}
