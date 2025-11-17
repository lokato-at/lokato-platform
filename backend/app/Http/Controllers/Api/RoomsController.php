<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RoomsController extends Controller
{
    /**
     * GET /api/v1/rooms
     * Alle Räume mit aktueller Belegung
     */
    public function index(): JsonResponse
    {
        // aktuelle Belegung pro Raum über child_locations
        $occupancies = DB::table('child_locations')
            ->select('room_id', DB::raw('COUNT(*) as count'))
            ->whereNotNull('room_id')
            ->groupBy('room_id')
            ->pluck('count', 'room_id');

        $rooms = Room::orderBy('name')->get()->map(function (Room $room) use ($occupancies) {
            $currentCount = (int) ($occupancies[$room->id] ?? 0);
            $overCapacity = $currentCount > $room->capacity + $room->tolerance;
            $withinTolerance = $currentCount > $room->capacity && $currentCount <= $room->capacity + $room->tolerance;

            return [
                'id'             => $room->id,
                'name'           => $room->name,
                'area'           => $room->area,
                'capacity'       => $room->capacity,
                'tolerance'      => $room->tolerance,
                'is_active'      => $room->is_active,
                'current_count'  => $currentCount,
                'status'         => [
                    'over_capacity'   => $overCapacity,
                    'within_tolerance'=> $withinTolerance,
                ],
            ];
        });

        return response()->json($rooms);
    }

    /**
     * GET /api/v1/rooms/{room}/occupancy
     * Details zur Belegung dieses Raums: welche Kinder sind aktuell drin?
     */
    public function occupancy(Room $room): JsonResponse
    {
        $room->load('locations.child');

        $children = $room->locations
            ->sortBy(fn ($loc) => $loc->child->name)
            ->map(function ($loc) {
                return [
                    'child_id'   => $loc->child->id,
                    'name'       => $loc->child->name,
                    'photo_url'  => $loc->child->photo_url,
                    'updated_at' => optional($loc->updated_at)->toIso8601String(),
                ];
            })
            ->values();

        return response()->json([
            'room' => [
                'id'        => $room->id,
                'name'      => $room->name,
                'area'      => $room->area,
                'capacity'  => $room->capacity,
                'tolerance' => $room->tolerance,
            ],
            'current_count' => $children->count(),
            'children'      => $children,
        ]);
    }
}
