<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppRuntimeState extends Model
{
    protected $table = 'app_runtime_state';

    protected $fillable = ['state_key', 'state_value'];

    public $timestamps = true;
}
