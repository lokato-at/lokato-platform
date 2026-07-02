<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine gescannte, aber (noch) keinem Kind zugeordnete Tracker-UID.
 * tracker_uid ist der Primaerschluessel (kein Auto-Increment) -> Upsert per
 * updateOrCreate haelt genau eine, aktuellste Zeile je UID.
 */
class TrackerSighting extends Model
{
    protected $table = 'tracker_sightings';
    protected $primaryKey = 'tracker_uid';
    public $incrementing = false;
    protected $keyType = 'string';

    // last_seen_at wird selbst gesetzt; keine created_at/updated_at-Spalten.
    public $timestamps = false;

    protected $fillable = [
        'tracker_uid',
        'device_id',
        'room_id',
        'last_seen_at',
    ];

    protected $casts = [
        'device_id'    => 'integer',
        'room_id'      => 'integer',
        'last_seen_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }
}
