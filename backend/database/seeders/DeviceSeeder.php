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
            'api_key' => hash('sha256', 'device-bastelraum-links'),
            'room_id' => $bastelraum->id,
        ]);

        Device::create([
            'name'    => 'Tür Turnsaal rechts',
            'api_key' => hash('sha256', 'device-turnsaal-rechts'),
            'room_id' => $turnsaal->id,
        ]);
    }
}
