<?php

namespace Database\Seeders;

use App\Models\Child;
use Illuminate\Database\Seeder;

class ChildSeeder extends Seeder
{
    public function run(): void
    {
        Child::insert([
            [
                'name'        => 'Anna Muster',
                'photo_url'   => null,
                'tracker_uid' => 'TAG-0001',
                'is_active'   => true,
            ],
            [
                'name'        => 'Ben Beispiel',
                'photo_url'   => null,
                'tracker_uid' => 'TAG-0002',
                'is_active'   => true,
            ],
        ]);
    }
}
