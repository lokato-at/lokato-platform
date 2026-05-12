<?php

namespace Database\Seeders;

use App\Models\Room;
use Illuminate\Database\Seeder;

class RoomSeeder extends Seeder
{
    public function run(): void
    {
        Room::insert([
//            [
//                'name'      => 'Kreativraum',
//                'area'      => 'EG',
//                'capacity'  => 7,
//                'tolerance' => 3,
//                'is_active' => true,
//            ],
//            [
//                'name'      => 'Turnsaal',
//                'area'      => 'EG',
//                'capacity'  => 20,
//                'tolerance' => 3,
//                'is_active' => true,
//            ],
            [
                'name'      => 'Garten',
                'area'      => 'Außenbereich',
                'capacity'  => 20,
                'tolerance' => 5,
                'is_active' => true,
            ],

//            [
//                'name'      => 'Speiseraum',
//                'area'      => 'EG',
//                'capacity'  => 7,
//                'tolerance' => 5,
//                'is_active' => true,
//            ],
//
//
//            [
//                'name'      => 'Bauraum',
//                'area'      => 'EG',
//                'capacity'  => 7,
//                'tolerance' => 3,
//                'is_active' => true,
//            ],
//
//            [
//                'name'      => 'Spiele-/Ruheraum',
//                'area'      => 'EG',
//                'capacity'  => 7,
//                'tolerance' => 3,
//                'is_active' => true,
//            ],
//
//            [
//                'name'      => 'Hausübungsraum',
//                'area'      => 'UG',
//                'capacity'  => 20,
//                'tolerance' => 5,
//                'is_active' => true,
//            ],
//
//            [
//                'name'      => 'Bewegungsraum',
//                'area'      => 'UG',
//                'capacity'  => 7,
//                'tolerance' => 3,
//                'is_active' => true,
//            ],

            [
                'name'      => 'Obergeschoss',
                'area'      => 'UG',
                'capacity'  => 36,
                'tolerance' => 3,
                'is_active' => true,
            ],


            [
                'name'      => 'Untergeschoss',
                'area'      => 'EG',
                'capacity'  => 36,
                'tolerance' => 3,
                'is_active' => true,
            ],




        ]);
    }
}
