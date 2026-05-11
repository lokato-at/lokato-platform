<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_runtime_state', function (Blueprint $table) {
            $table->id();
            $table->string('state_key', 100)->unique();
            $table->string('state_value', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_runtime_state');
    }
};
