<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Child extends Model
{
    protected $table = 'children';

    public $timestamps = false; // keine created_at / updated_at Spalten

    protected $fillable = [
        'name',
        'photo_url',
        'tracker_uid',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function location(): HasOne
    {
        return $this->hasOne(ChildLocation::class, 'child_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(MovementLog::class, 'child_id');
    }
}
