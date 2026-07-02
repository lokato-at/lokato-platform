<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\MovementLog;
use App\Models\Room;
use App\Support\OccupancySnapshotBuilder;
use App\Support\SseChangeSignal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SseStreamController extends Controller
{
    private const HEARTBEAT_SECONDS = 15;
    // 500ms ok dank Cache-Gate: im Idle pro Tick nur ein Cache::get statt DB-Queries.
    private const POLL_INTERVAL_MICROSECONDS = 500_000;
    private const STREAM_RETRY_MILLISECONDS = 5000;

    public function __construct(
        private readonly OccupancySnapshotBuilder $occupancySnapshotBuilder,
        private readonly SseChangeSignal $sseChangeSignal,
    ) {
    }

    /**
     * Query-Params: room (int, optional - scopt Events auf diesen Raum),
     * initial (bool - schickt nach Connect direkt einen Occupancy-Snapshot).
     */
    public function stream(Request $request): StreamedResponse
    {
        $roomParam = $request->query('room');
        $roomId = ($roomParam !== null && $roomParam !== '') ? (int) $roomParam : null;
        $sendInitial = filter_var($request->query('initial', false), FILTER_VALIDATE_BOOL);

        if ($roomId !== null) {
            // Vor dem Streaming-Start validieren, sonst sitzt der Client in einem
            // stillen Loop fest.
            Room::query()->select(['id'])->findOrFail($roomId);
        }

        return response()->stream(function () use ($request, $roomId, $sendInitial) {
            $this->runStream($request, $roomId, $sendInitial);
        }, 200, $this->streamHeaders());
    }

    protected function runStream(Request $request, ?int $scopedRoomId, bool $sendInitial): void
    {
        $clientId = (string) Str::uuid();
        $this->prepareStream();
        [$lastMovementId, $lastAlertId] = $this->resolveStreamCursor($request);
        // Baseline = aktueller Max-Wert, sonst werden beim Connect alle Räume
        // als "geändert" gepusht.
        $lastRoomChangeAt = (string) (Room::query()->max('updated_at') ?? '1970-01-01 00:00:00');
        $startedAt = microtime(true);
        $maxDurationSeconds = (int) config('app.sse_max_connection_seconds', 60);
        $lastHeartbeatAt = microtime(true);
        $lastChangeSeen = $this->sseChangeSignal->lastChangeAt();
        $lastChildrenChangeSeen = $this->sseChangeSignal->lastChildrenChangeAt();
        $scope = $scopedRoomId === null ? 'dashboard' : "room:{$scopedRoomId}";

        Log::channel('sse')->info('SSE client connected', [
            'client_id' => $clientId,
            'scope' => $scope,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'cursor' => compact('lastMovementId', 'lastAlertId'),
        ]);

        $this->sendRetryInstruction();
        $this->sendEvent('stream.ready', [
            'scope' => $scope,
            'connected_at' => now()->toIso8601String(),
        ], $this->formatEventCursor($lastMovementId, $lastAlertId));

        if ($sendInitial && $scopedRoomId !== null) {
            $room = Room::query()->select(['id', 'name', 'capacity', 'tolerance'])->find($scopedRoomId);
            if ($room) {
                $snapshot = $this->occupancySnapshotBuilder
                    ->forRoomIds([$scopedRoomId], true)
                    ->get($scopedRoomId, ['current_count' => 0, 'children' => []]);

                $this->sendEvent('room.occupancy.updated', $this->occupancyPayload($room, $snapshot), $this->formatEventCursor($lastMovementId, $lastAlertId));
            }
        }

        $this->flushStream();

        // Erster Tick immer pollen, damit Last-Event-ID-Reconnects ihren Backlog aufholen.
        $forceInitialPoll = true;

        while (! connection_aborted()) {
            $currentChange = $this->sseChangeSignal->lastChangeAt();
            $cacheActive = $currentChange > 0.0;
            $shouldQueryDb = $forceInitialPoll
                || ! $cacheActive
                || $currentChange > $lastChangeSeen;

            if ($shouldQueryDb) {
                $forceInitialPoll = false;
                $lastChangeSeen = max($lastChangeSeen, $currentChange);

                [$lastMovementId, $lastAlertId, $lastRoomChangeAt] = $this->pollIteration(
                    $scopedRoomId,
                    $lastMovementId,
                    $lastAlertId,
                    $lastRoomChangeAt,
                );

                // Children-Aenderungen ohne Movement (z.B. Admin-Toggle) wuerden vom
                // movement-basierten Poll nicht gesehen → separater Refresh-Pfad.
                $currentChildrenChange = $this->sseChangeSignal->lastChildrenChangeAt();
                if ($currentChildrenChange > $lastChildrenChangeSeen) {
                    $lastChildrenChangeSeen = $currentChildrenChange;
                    $this->emitFullRoomOccupancyRefresh($scopedRoomId, $lastMovementId, $lastAlertId);
                }
            }

            $now = microtime(true);
            if (($now - $lastHeartbeatAt) >= self::HEARTBEAT_SECONDS) {
                echo ': heartbeat ' . now()->toIso8601String() . "\n\n";
                $lastHeartbeatAt = $now;
            }

            $this->flushStream();

            if (($now - $startedAt) >= $maxDurationSeconds) {
                $this->sendEvent('stream.draining', [
                    'reason' => 'max_connection_age_reached',
                    'reconnect' => true,
                ], $this->formatEventCursor($lastMovementId, $lastAlertId));
                $this->flushStream();
                break;
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }

        Log::channel('sse')->info('SSE client disconnected', [
            'client_id' => $clientId,
            'scope' => $scope,
            'cursor' => compact('lastMovementId', 'lastAlertId'),
        ]);
    }

    /**
     * @return array{0:int, 1:int, 2:string}  aktualisierte Cursor (lastMovementId, lastAlertId, lastRoomChangeAt)
     */
    protected function pollIteration(?int $scopedRoomId, int $lastMovementId, int $lastAlertId, string $lastRoomChangeAt): array
    {
        $changedRoomIds = [];

        $movementQuery = MovementLog::query()
            ->select(['id', 'child_id', 'from_room_id', 'to_room_id', 'device_id', 'source', 'occurred_at'])
            ->with([
                'child:id,name',
                'fromRoom:id,name',
                'toRoom:id,name',
            ])
            ->where('id', '>', $lastMovementId)
            ->orderBy('id')
            ->limit(100);

        if ($scopedRoomId !== null) {
            $movementQuery->where(function ($q) use ($scopedRoomId) {
                $q->where('from_room_id', $scopedRoomId)
                  ->orWhere('to_room_id', $scopedRoomId);
            });
        }

        $movements = $movementQuery->get();

        foreach ($movements as $movement) {
            $lastMovementId = $movement->id;
            // Payload-Schema muss dem von /api/v1/movement-log entsprechen, sonst
            // rendert die Dashboard-Liste „? → ?" statt der Namen.
            $this->sendEvent('child.moved', [
                'id' => $movement->id,
                'child_id' => $movement->child_id,
                'child' => $movement->child ? [
                    'id' => $movement->child->id,
                    'name' => $movement->child->name,
                ] : null,
                'from_room_id' => $movement->from_room_id,
                'from_room' => $movement->fromRoom ? [
                    'id' => $movement->fromRoom->id,
                    'name' => $movement->fromRoom->name,
                ] : null,
                'to_room_id' => $movement->to_room_id,
                'to_room' => $movement->toRoom ? [
                    'id' => $movement->toRoom->id,
                    'name' => $movement->toRoom->name,
                ] : null,
                'device_id' => $movement->device_id,
                'source' => $movement->source,
                'occurred_at' => $movement->occurred_at?->toIso8601String(),
            ], $this->formatEventCursor($lastMovementId, $lastAlertId));

            if ($scopedRoomId === null) {
                if ($movement->from_room_id) {
                    $changedRoomIds[$movement->from_room_id] = true;
                }
                if ($movement->to_room_id) {
                    $changedRoomIds[$movement->to_room_id] = true;
                }
            } else {
                $changedRoomIds[$scopedRoomId] = true;
            }
        }

        if ($changedRoomIds !== []) {
            $roomIds = array_keys($changedRoomIds);
            $rooms = Room::query()
                ->select(['id', 'name', 'capacity', 'tolerance'])
                ->whereIn('id', $roomIds)
                ->get()
                ->keyBy('id');
            $snapshots = $this->occupancySnapshotBuilder->forRoomIds($roomIds, true);

            foreach ($snapshots as $roomId => $snapshot) {
                $room = $rooms->get($roomId);
                if (! $room) {
                    continue;
                }

                $this->sendEvent('room.occupancy.updated', $this->occupancyPayload($room, $snapshot), $this->formatEventCursor($lastMovementId, $lastAlertId));
            }
        }

        $alertQuery = Alert::query()
            ->select(['id', 'room_id', 'level', 'message', 'created_at', 'resolved_at'])
            ->where('id', '>', $lastAlertId)
            ->orderBy('id')
            ->limit(100);

        if ($scopedRoomId !== null) {
            $alertQuery->where('room_id', $scopedRoomId);
        }

        $alerts = $alertQuery->get();

        foreach ($alerts as $alert) {
            $lastAlertId = $alert->id;
            $this->sendEvent('room.alert.raised', [
                'id' => $alert->id,
                'room_id' => $alert->room_id,
                'level' => $alert->level,
                'message' => $alert->message,
                'created_at' => $alert->created_at?->toIso8601String(),
                'resolved_at' => $alert->resolved_at?->toIso8601String(),
            ], $this->formatEventCursor($lastMovementId, $lastAlertId));
        }

        $roomQuery = Room::query()
            ->select(['id', 'name', 'area', 'icon', 'capacity', 'tolerance', 'is_active', 'updated_at'])
            ->where('updated_at', '>', $lastRoomChangeAt)
            ->orderBy('updated_at')
            ->limit(50);

        if ($scopedRoomId !== null) {
            $roomQuery->where('id', $scopedRoomId);
        }

        $changedRooms = $roomQuery->get();

        $occupancyRefreshRoomIds = [];
        foreach ($changedRooms as $room) {
            if ($room->updated_at) {
                $lastRoomChangeAt = $room->updated_at->toDateTimeString();
            }

            $this->sendEvent('room.status.updated', [
                'id' => $room->id,
                'name' => $room->name,
                'area' => $room->area,
                'icon' => $room->icon,
                'capacity' => $room->capacity,
                'tolerance' => $room->tolerance,
                'is_active' => (bool) $room->is_active,
            ], $this->formatEventCursor($lastMovementId, $lastAlertId));

            // Bei Capacity/Tolerance-Aenderung muss status.over_capacity /
            // within_tolerance neu bewertet werden, sonst Stale-Anzeige bis zum
            // naechsten Scan.
            if (! isset($occupancyRefreshRoomIds[$room->id])) {
                $occupancyRefreshRoomIds[$room->id] = $room;
            }
        }

        if ($occupancyRefreshRoomIds !== []) {
            $snapshots = $this->occupancySnapshotBuilder->forRoomIds(array_keys($occupancyRefreshRoomIds), true);
            foreach ($snapshots as $roomId => $snapshot) {
                $room = $occupancyRefreshRoomIds[$roomId] ?? null;
                if (! $room) continue;

                $this->sendEvent('room.occupancy.updated', $this->occupancyPayload($room, $snapshot), $this->formatEventCursor($lastMovementId, $lastAlertId));
            }
        }

        return [$lastMovementId, $lastAlertId, $lastRoomChangeAt];
    }

    /**
     * Children-Toggle aendert keine Location, also kennen wir den Raum nicht;
     * deshalb Full-Refresh ueber alle Raeume (oder scoped Raum bei Tablet).
     */
    protected function emitFullRoomOccupancyRefresh(?int $scopedRoomId, int $lastMovementId, int $lastAlertId): void
    {
        $roomIds = $scopedRoomId !== null
            ? [$scopedRoomId]
            : Room::query()->pluck('id')->all();

        if ($roomIds === []) return;

        $rooms = Room::query()
            ->select(['id', 'name', 'capacity', 'tolerance'])
            ->whereIn('id', $roomIds)
            ->get()
            ->keyBy('id');
        $snapshots = $this->occupancySnapshotBuilder->forRoomIds($roomIds, true);

        foreach ($snapshots as $roomId => $snapshot) {
            $room = $rooms->get($roomId);
            if (! $room) continue;

            $this->sendEvent('room.occupancy.updated', $this->occupancyPayload($room, $snapshot), $this->formatEventCursor($lastMovementId, $lastAlertId));
        }
    }

    /**
     * status (over_capacity/within_tolerance) muss mit ausgeliefert werden, sonst
     * koennen Dashboard/Tablet die Warn-/Ueberbelegungs-Zaehler nicht live aktualisieren.
     */
    protected function occupancyPayload(Room $room, array $snapshot): array
    {
        $currentCount = (int) ($snapshot['current_count'] ?? 0);
        $capacity = (int) ($room->capacity ?? 0);
        $tolerance = (int) ($room->tolerance ?? 0);

        $overCapacity = $capacity > 0 && $currentCount > $capacity + $tolerance;
        $withinTolerance = $capacity > 0 && $currentCount > $capacity && $currentCount <= $capacity + $tolerance;

        return [
            'room_id' => $room->id,
            'room_name' => $room->name,
            'capacity' => $room->capacity,
            'tolerance' => $room->tolerance,
            'current_count' => $currentCount,
            'children' => $snapshot['children'] ?? [],
            'status' => [
                'over_capacity' => $overCapacity,
                'within_tolerance' => $withinTolerance,
            ],
        ];
    }

    protected function prepareStream(): void
    {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        set_time_limit(0);
        ignore_user_abort(true);
    }

    /**
     * @return array{0:int, 1:int}
     */
    protected function resolveStreamCursor(Request $request): array
    {
        $lastMovementId = (int) (MovementLog::max('id') ?? 0);
        $lastAlertId = (int) (Alert::max('id') ?? 0);
        $lastEventId = $request->headers->get('Last-Event-ID') ?? $request->query('last_event_id');

        if (! is_string($lastEventId) || $lastEventId === '') {
            return [$lastMovementId, $lastAlertId];
        }

        preg_match('/movement:(\d+)/', $lastEventId, $movementMatch);
        preg_match('/alert:(\d+)/', $lastEventId, $alertMatch);

        if (isset($movementMatch[1])) {
            $lastMovementId = (int) $movementMatch[1];
        }
        if (isset($alertMatch[1])) {
            $lastAlertId = (int) $alertMatch[1];
        }

        return [$lastMovementId, $lastAlertId];
    }

    protected function formatEventCursor(int $lastMovementId, int $lastAlertId): string
    {
        return sprintf('movement:%d;alert:%d', $lastMovementId, $lastAlertId);
    }

    protected function sendRetryInstruction(): void
    {
        echo 'retry: ' . self::STREAM_RETRY_MILLISECONDS . "\n\n";
    }

    protected function sendEvent(string $eventName, array $data, ?string $id = null): void
    {
        if ($id !== null) {
            echo "id: {$id}\n";
        }

        echo "event: {$eventName}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    }

    protected function flushStream(): void
    {
        @ob_flush();
        flush();
    }

    /**
     * @return array<string, string>
     */
    protected function streamHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
    }
}
