<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('movement_log', function (Blueprint $table) {
            $table->unsignedBigInteger('id', true); // BIGINT UNSIGNED AUTO_INCREMENT
            $table->unsignedBigInteger('child_id');
            $table->unsignedBigInteger('from_room_id')->nullable();
            $table->unsignedBigInteger('to_room_id')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            // Ist Anpassbar, für den Prototype ist es 'mqtt_scanner'
            $table->enum('source', ['device', 'manual', 'api', 'mqtt_scanner'])->default('mqtt_scanner');
            $table->dateTime('occurred_at');

            $table->foreign('child_id')
                ->references('id')->on('children')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreign('from_room_id')
                ->references('id')->on('rooms')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreign('to_room_id')
                ->references('id')->on('rooms')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreign('device_id')
                ->references('id')->on('devices')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->index(['child_id', 'occurred_at'], 'idx_mlog_child_time');
            $table->index(['to_room_id', 'occurred_at'], 'idx_mlog_to_room_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movement_log');
    }
};
