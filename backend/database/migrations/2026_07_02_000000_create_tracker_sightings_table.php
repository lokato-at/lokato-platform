<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sichtungen von Tracker-UIDs, die (noch) keinem Kind gehoeren.
 * Speist den Admin-Anlern-Modus ("welche neuen Tracker werden gerade gescannt?").
 *
 * tracker_uid ist der natuerliche Primaerschluessel -> pro UID genau eine
 * (aktuellste) Zeile via Upsert. Die Tabelle bleibt damit winzig: eine Zeile
 * je unbekanntem Tracker, und sobald der Tracker einem Kind zugewiesen wird,
 * loescht der ChildAdminController die Zeile wieder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracker_sightings', function (Blueprint $table) {
            $table->string('tracker_uid', 100)->primary();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->unsignedBigInteger('room_id')->nullable();
            $table->timestamp('last_seen_at');
            $table->index('last_seen_at');

            $table->foreign('device_id')->references('id')->on('devices')->nullOnDelete();
            $table->foreign('room_id')->references('id')->on('rooms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracker_sightings');
    }
};
