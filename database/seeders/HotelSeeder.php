<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class HotelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
{
    \App\Models\Hotel::create([
        'nom' => 'Hotel Dakar',
        'adresse' => 'Plateau',
        'email' => 'contact@hotel.com',
        'telephone' => '771234567',
        'prix' => 25000,
        'currency' => 'XOF',
        'photo' => 'hotels/default.jpg',
    ]);
}
}
