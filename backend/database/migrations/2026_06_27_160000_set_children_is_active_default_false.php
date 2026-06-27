<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Children sind per Default INAKTIV. Erst beim ersten Scan eines Tages
 * setzt ScanIngestService::ingestScan() das Kind auf is_active=true.
 * Der DailyActiveResetCommand (cron 01:00) setzt nachts alle wieder
 * auf false und leert child_locations.
 *
 * Diese Migration:
 *  1) Ändert den DB-Default von true auf false (für neue Einträge).
 *  2) Backfilled alle bestehenden Rows auf false (sonst bleibt der
 *     historische Zustand bestehen).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE children MODIFY is_active BOOLEAN NOT NULL DEFAULT 0');
        DB::table('children')->update(['is_active' => false]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE children MODIFY is_active BOOLEAN NOT NULL DEFAULT 1');
    }
};
