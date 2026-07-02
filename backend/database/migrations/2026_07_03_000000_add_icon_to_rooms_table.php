<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optionales Icon/Bild pro Raum — nur für die Tablet-Ansicht (/tablet/<id>).
 * Speichert lediglich den Dateinamen eines Bildes aus dem public/room-icons-
 * Ordner (oder einen vollen Pfad). Keine ID-Kopplung, kein Unique-Zwang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('icon', 100)->nullable()->after('area');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }
};
