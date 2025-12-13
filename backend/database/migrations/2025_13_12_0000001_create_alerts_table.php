<?php
// database/migrations/xxxx_xx_xx_create_alerts_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('room_id');
            $table->string('level', 50); // e.g. "warning", "critical"
            $table->string('message');
            $table->dateTime('created_at');
            $table->dateTime('resolved_at')->nullable();

            $table->foreign('room_id')->references('id')->on('rooms');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
