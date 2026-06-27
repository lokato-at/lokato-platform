<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `created_at` / `updated_at` to the rooms table so the SSE stream
 * controller can poll for room-status changes (is_active toggle, capacity
 * change, rename) without needing a separate change-log table.
 *
 * Existing rows get updated_at = current time so they don't accidentally
 * trigger a push to every connected client on first poll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->timestamps();
        });

        // Seed bestehende Räume mit dem aktuellen Zeitpunkt — sonst landet
        // NULL in updated_at und unsere where('updated_at', '>', baseline)
        // ignoriert diese Zeilen unabhängig von späteren Änderungen.
        \DB::table('rooms')->update([
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropTimestamps();
        });
    }
};
