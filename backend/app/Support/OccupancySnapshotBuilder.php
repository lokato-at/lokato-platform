<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OccupancySnapshotBuilder
{
    public function forAllRooms(bool $includeChildren = false): Collection
    {
        $roomIds = DB::table('rooms')->pluck('id')->all();

        return $this->forRoomIds($roomIds, $includeChildren);
    }

    /**
     * @param  array<int>  $roomIds
     * @return Collection<int, array{current_count:int, children?: array<int, array<string, mixed>>}>
     */
    public function forRoomIds(array $roomIds, bool $includeChildren = false): Collection
    {
        if ($roomIds === []) {
            return collect();
        }

        // Nur AKTIVE Kinder werden als anwesend gezählt. Inaktive Kinder
        // bleiben evtl. noch in child_locations (z.B. zwischen Checkout und
        // DailyActiveReset), sollen aber weder im current_count noch in der
        // children-Liste auftauchen — sonst zeigen Tablet/Dashboard "Geister-
        // belegung" von Kindern die de facto nicht da sind.
        $counts = DB::table('child_locations')
            ->join('children', 'children.id', '=', 'child_locations.child_id')
            ->select('child_locations.room_id', DB::raw('COUNT(*) as current_count'))
            ->whereIn('child_locations.room_id', $roomIds)
            ->where('children.is_active', true)
            ->groupBy('child_locations.room_id')
            ->pluck('current_count', 'room_id');

        $childrenByRoom = collect();

        if ($includeChildren) {
            $childrenByRoom = DB::table('child_locations')
                ->join('children', 'children.id', '=', 'child_locations.child_id')
                ->whereIn('child_locations.room_id', $roomIds)
                ->where('children.is_active', true)
                ->orderBy('children.name')
                ->get([
                    'child_locations.room_id',
                    'child_locations.updated_at',
                    'children.id',
                    'children.name',
                    'children.photo_url',
                    'children.is_active',
                ])
                ->groupBy('room_id')
                ->map(fn (Collection $rows) => $rows->map(fn ($row) => [
                    'child_id' => (int) $row->id,
                    'id' => (int) $row->id,
                    'name' => $row->name,
                    'photo_url' => $row->photo_url,
                    'is_active' => (bool) $row->is_active,
                    'updated_at' => $row->updated_at ? CarbonImmutable::parse($row->updated_at)->toIso8601String() : null,
                ])->values()->all());
        }

        return collect($roomIds)
            ->mapWithKeys(function (int $roomId) use ($counts, $childrenByRoom, $includeChildren) {
                $snapshot = [
                    'current_count' => (int) ($counts[$roomId] ?? 0),
                ];

                if ($includeChildren) {
                    $snapshot['children'] = $childrenByRoom->get($roomId, []);
                }

                return [$roomId => $snapshot];
            });
    }
}
