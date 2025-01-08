<?php

namespace Database\Seeders;

use App\Models\DebitCardTransaction;
use Illuminate\Database\Seeder;

class DebitCardTransactionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        return DebitCardTransaction::factory()->count(10)->create();
    }
}
