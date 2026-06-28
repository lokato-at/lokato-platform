<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Default-Flip auf false plus Backfill. Erst der erste Scan eines Tages
 * setzt is_active=true, DailyActiveResetCommand reset's nachts wieder.
 *
 * Driver-Guard: ALTER TABLE ... MODIFY ist MySQL-Syntax. Auf SQLite (Tests)
 * irrelevant — Child::create setzt is_active dort immer explizit.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE children MODIFY is_active BOOLEAN NOT NULL DEFAULT 0');
        }
        DB::table('children')->update(['is_active' => false]);
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE children MODIFY is_active BOOLEAN NOT NULL DEFAULT 1');
        }
    }
};
