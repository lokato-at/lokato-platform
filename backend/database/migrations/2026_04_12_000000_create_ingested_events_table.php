<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ingested_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_hash', 64)->unique();
            $table->string('topic', 255)->nullable();
            $table->string('device_key', 100);
            $table->string('tracker_uid', 100);
            $table->timestamp('event_time')->nullable();
            $table->timestamp('ingested_at')->useCurrent();

            $table->index(['device_key', 'event_time'], 'idx_ingested_device_event_time');
            $table->index(['tracker_uid', 'event_time'], 'idx_ingested_tracker_event_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingested_events');
    }
};
