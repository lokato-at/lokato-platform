<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovementLog extends Model
{
    protected $table = 'movement_log';
    public $timestamps = false;

    protected $fillable = [
        'child_id',
        'from_room_id',
        'to_room_id',
        'device_id',
        'source',
        'occurred_at',
    ];

    protected $casts = [
        'child_id'     => 'integer',
        'from_room_id' => 'integer',
        'to_room_id'   => 'integer',
        'device_id'    => 'integer',
        'occurred_at'  => 'datetime',
    ];

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class, 'child_id');
    }

    public function fromRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'from_room_id');
    }

    public function toRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'to_room_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
