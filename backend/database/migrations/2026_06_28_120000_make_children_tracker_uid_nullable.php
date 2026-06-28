<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tracker_uid wird optional, weil Kinder vor der RFID-Zuweisung angelegt werden.
 * Unique-Index bleibt — MySQL/MariaDB/SQLite erlauben mehrere NULLs in einem UNIQUE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->string('tracker_uid', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->string('tracker_uid', 100)->nullable(false)->change();
        });
    }
};
