<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('children', function (Blueprint $table) {
            $table->id(); // UNSIGNED BIGINT AUTO_INCREMENT
            $table->string('name', 100);
            $table->string('photo_url', 255)->nullable();
            $table->string('tracker_uid', 100)->unique();
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('children');
    }
};
