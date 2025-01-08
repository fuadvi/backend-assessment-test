<?php

namespace Database\Seeders;

use App\Models\DebitCard;
use Illuminate\Database\Seeder;

class DebitCardSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        return DebitCard::factory()->create();
    }
}
