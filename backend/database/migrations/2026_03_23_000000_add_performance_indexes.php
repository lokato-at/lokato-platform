<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->index('room_id', 'idx_devices_room_id');
        });

        Schema::table('child_locations', function (Blueprint $table) {
            $table->index(['room_id', 'updated_at'], 'idx_child_locations_room_updated');
        });

        Schema::table('movement_log', function (Blueprint $table) {
            $table->index('occurred_at', 'idx_mlog_occurred_at');
            $table->index(['from_room_id', 'id'], 'idx_mlog_from_room_id');
            $table->index(['to_room_id', 'id'], 'idx_mlog_to_room_id');
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->index(['room_id', 'id'], 'idx_alerts_room_id');
            $table->index('created_at', 'idx_alerts_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex('idx_devices_room_id');
        });

        Schema::table('child_locations', function (Blueprint $table) {
            $table->dropIndex('idx_child_locations_room_updated');
        });

        Schema::table('movement_log', function (Blueprint $table) {
            $table->dropIndex('idx_mlog_occurred_at');
            $table->dropIndex('idx_mlog_from_room_id');
            $table->dropIndex('idx_mlog_to_room_id');
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->dropIndex('idx_alerts_room_id');
            $table->dropIndex('idx_alerts_created_at');
        });
    }
};
