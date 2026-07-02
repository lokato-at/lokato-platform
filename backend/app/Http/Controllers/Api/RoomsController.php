<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Support\OccupancySnapshotBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomsController extends Controller
{
    public function __construct(private readonly OccupancySnapshotBuilder $occupancySnapshotBuilder)
    {
    }

    /**
     * GET /api/v1/rooms
     * Alle Räume mit aktueller Belegung
     */
    public function index(Request $request): JsonResponse
    {
        $includeChildren = $request->boolean('include_children');

        $rooms = Room::query()
            ->select(['id', 'name', 'area', 'capacity', 'tolerance', 'is_active'])
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->get();

        $snapshots = $this->occupancySnapshotBuilder->forRoomIds($rooms->pluck('id')->all(), $includeChildren);

        $payload = $rooms->map(function (Room $room) use ($snapshots, $includeChildren) {
            $snapshot = $snapshots->get($room->id, ['current_count' => 0, 'children' => []]);
            $currentCount = (int) ($snapshot['current_count'] ?? 0);
            $overCapacity = $currentCount > $room->capacity + $room->tolerance;
            $withinTolerance = $currentCount > $room->capacity && $currentCount <= $room->capacity + $room->tolerance;

            $roomPayload = [
                'id' => $room->id,
                'name' => $room->name,
                'area' => $room->area,
                'capacity' => $room->capacity,
                'tolerance' => $room->tolerance,
                'is_active' => $room->is_active,
                'current_count' => $currentCount,
                'status' => [
                    'over_capacity' => $overCapacity,
                    'within_tolerance' => $withinTolerance,
                ],
            ];

            if ($includeChildren) {
                $roomPayload['children'] = $snapshot['children'] ?? [];
            }

            return $roomPayload;
        });

        return response()->json($payload);
    }

    /**
     * GET /api/v1/rooms/{room}/occupancy
     */
    public function occupancy(Room $room): JsonResponse
    {
        $snapshot = $this->occupancySnapshotBuilder->forRoomIds([$room->id], true)->get($room->id, [
            'current_count' => 0,
            'children' => [],
        ]);

        return response()->json([
            'room' => [
                'id' => $room->id,
                'name' => $room->name,
                'area' => $room->area,
                // icon: Bild fuer die Tablet-Ansicht (ersetzt den Platzhalter).
                'icon' => $room->icon,
                'capacity' => $room->capacity,
                'tolerance' => $room->tolerance,
                // Tablet-View braucht is_active fuer den "Raum geschlossen"-Banner.
                // SSE-Events (room.status.updated) liefern es schon, der Initial-
                // Snapshot beim Tablet-Open ist die einzige Stelle, an der wir es
                // nochmal mitgeben muessen.
                'is_active' => (bool) $room->is_active,
            ],
            'current_count' => (int) $snapshot['current_count'],
            'children' => $snapshot['children'],
        ]);
    }
}
