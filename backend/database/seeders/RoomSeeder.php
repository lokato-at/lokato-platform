<?php

namespace Database\Seeders;

use App\Models\Room;
use Illuminate\Database\Seeder;

class RoomSeeder extends Seeder
{
    public function run(): void
    {
        Room::insert([
            [
                'name'      => 'Bastelraum',
                'area'      => 'UG',
                'capacity'  => 15,
                'tolerance' => 2,
                'is_active' => true,
            ],
            [
                'name'      => 'Turnsaal',
                'area'      => 'EG',
                'capacity'  => 20,
                'tolerance' => 3,
                'is_active' => true,
            ],
            [
                'name'      => 'Garten',
                'area'      => 'Außenbereich',
                'capacity'  => 30,
                'tolerance' => 5,
                'is_active' => true,
            ],
        ]);
    }
}
