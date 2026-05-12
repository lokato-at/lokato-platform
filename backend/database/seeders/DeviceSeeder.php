<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Models\Room;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DeviceSeeder extends Seeder
{
    public function run(): void
    {
        $untergeschoss = Room::where('name', 'Untergeschoss')->first();
        $obergeschoss   = Room::where('name', 'Obergeschoss')->first();
        $garten   = Room::where('name', 'Garten')->first();

        Device::create([
            'name'    => 'Untergeschoss',
            'device_key' => 'RaspberryChild01',
            'room_id' => $untergeschoss->id,
        ]);

        Device::create([
            'name'    => 'Obergeschoss',
            'device_key' => 'RaspberryChild02',
            'room_id' => $obergeschoss->id,
        ]);

        Device::create([
            'name'    => 'Garten',
            'device_key' => 'RaspberryChild03',
            'room_id' => $garten->id,
        ]);



    }
}
