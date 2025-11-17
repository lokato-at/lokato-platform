<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    protected $table = 'rooms';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'area',
        'capacity',
        'tolerance',
        'is_active',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'tolerance' => 'integer',
        'is_active' => 'boolean',
    ];

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'room_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(ChildLocation::class, 'room_id');
    }

    public function movementsTo(): HasMany
    {
        return $this->hasMany(MovementLog::class, 'to_room_id');
    }

    public function movementsFrom(): HasMany
    {
        return $this->hasMany(MovementLog::class, 'from_room_id');
    }
}
