<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->char('api_key', 64)->unique();
            $table->unsignedBigInteger('room_id');
            $table->timestamp('last_seen')->nullable();

            $table->foreign('room_id')
                ->references('id')->on('rooms')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
