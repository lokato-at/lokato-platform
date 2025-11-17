<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('child_locations', function (Blueprint $table) {
            $table->unsignedBigInteger('child_id')->primary();
            $table->unsignedBigInteger('room_id')->nullable();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('child_id')
                ->references('id')->on('children')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreign('room_id')
                ->references('id')->on('rooms')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_locations');
    }
};
