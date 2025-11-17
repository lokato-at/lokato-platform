<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChildLocation extends Model
{
    protected $table = 'child_locations';
    protected $primaryKey = 'child_id';
    public $incrementing = false;

    // wir managen updated_at selber (wegen event_time)
    public $timestamps = false;

    protected $fillable = [
        'child_id',
        'room_id',
        'updated_at',
    ];

    protected $casts = [
        'child_id'   => 'integer',
        'room_id'    => 'integer',
        'updated_at' => 'datetime',
    ];

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class, 'child_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }
}
