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

        $counts = DB::table('child_locations')
            ->select('room_id', DB::raw('COUNT(*) as current_count'))
            ->whereIn('room_id', $roomIds)
            ->groupBy('room_id')
            ->pluck('current_count', 'room_id');

        $childrenByRoom = collect();

        if ($includeChildren) {
            $childrenByRoom = DB::table('child_locations')
                ->join('children', 'children.id', '=', 'child_locations.child_id')
                ->whereIn('child_locations.room_id', $roomIds)
                ->orderBy('children.name')
                ->get([
                    'child_locations.room_id',
                    'child_locations.updated_at',
                    'children.id',
                    'children.name',
                    'children.photo_url',
                ])
                ->groupBy('room_id')
                ->map(fn (Collection $rows) => $rows->map(fn ($row) => [
                    'child_id' => (int) $row->id,
                    'id' => (int) $row->id,
                    'name' => $row->name,
                    'photo_url' => $row->photo_url,
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
