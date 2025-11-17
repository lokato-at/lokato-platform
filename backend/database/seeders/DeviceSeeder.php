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
        $bastelraum = Room::where('name', 'Bastelraum')->first();
        $turnsaal   = Room::where('name', 'Turnsaal')->first();

        Device::create([
            'name'    => 'Tür Bastelraum links',
            'device_key' => 'RaspberryChild01',
            'room_id' => $bastelraum->id,
        ]);

        Device::create([
            'name'    => 'Tür Turnsaal rechts',
            'device_key' => 'RaspberryChild02',
            'room_id' => $turnsaal->id,
        ]);
    }
}
