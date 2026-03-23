<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\MovementLog;
use App\Models\Room;
use App\Support\OccupancySnapshotBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SseStreamController extends Controller
{
    private const HEARTBEAT_SECONDS = 15;
    private const POLL_INTERVAL_MICROSECONDS = 1_000_000;
    private const STREAM_RETRY_MILLISECONDS = 5000;

    public function __construct(private readonly OccupancySnapshotBuilder $occupancySnapshotBuilder)
    {
    }

    public function dashboard(Request $request): StreamedResponse
    {
        return response()->stream(function () use ($request) {
            $this->runDashboardStream($request);
        }, 200, $this->streamHeaders());
    }

    public function room(Request $request, int $roomId): StreamedResponse
    {
        return response()->stream(function () use ($request, $roomId) {
            $this->runRoomStream($request, $roomId);
        }, 200, $this->streamHeaders());
    }

    protected function runDashboardStream(Request $request): void
    {
        $clientId = (string) Str::uuid();
        $this->prepareStream();
        [$lastMovementId, $lastAlertId] = $this->resolveStreamCursor($request);
        $startedAt = microtime(true);
        $maxDurationSeconds = (int) config('app.sse_max_connection_seconds', 60);
        $lastHeartbeatAt = microtime(true);

        Log::channel('sse')->info('Dashboard SSE client connected', [
            'client_id' => $clientId,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'cursor' => compact('lastMovementId', 'lastAlertId'),
        ]);

        $this->sendRetryInstruction();
        $this->sendEvent('stream.ready', [
            'scope' => 'dashboard',
            'connected_at' => now()->toIso8601String(),
        ], $this->formatEventCursor($lastMovementId, $lastAlertId));
        $this->flushStream();

        while (! connection_aborted()) {
            $changedRoomIds = [];

            $movements = MovementLog::query()
                ->select(['id', 'child_id', 'from_room_id', 'to_room_id', 'device_id', 'source', 'occurred_at'])
                ->where('id', '>', $lastMovementId)
                ->orderBy('id')
                ->limit(100)
                ->get();

            foreach ($movements as $movement) {
                $lastMovementId = $movement->id;
                $this->sendEvent('child.moved', [
                    'id' => $movement->id,
                    'child_id' => $movement->child_id,
                    'from_room_id' => $movement->from_room_id,
                    'to_room_id' => $movement->to_room_id,
                    'device_id' => $movement->device_id,
                    'source' => $movement->source,
                    'occurred_at' => $movement->occurred_at?->toIso8601String(),
                ], $this->formatEventCursor($lastMovementId, $lastAlertId));

                if ($movement->from_room_id) {
                    $changedRoomIds[$movement->from_room_id] = true;
                }
                if ($movement->to_room_id) {
                    $changedRoomIds[$movement->to_room_id] = true;
                }
            }

            if ($changedRoomIds !== []) {
                $rooms = Room::query()
                    ->select(['id', 'name'])
                    ->whereIn('id', array_keys($changedRoomIds))
                    ->get()
                    ->keyBy('id');
                $snapshots = $this->occupancySnapshotBuilder->forRoomIds(array_keys($changedRoomIds), true);

                foreach ($snapshots as $roomId => $snapshot) {
                    $room = $rooms->get($roomId);
                    if (! $room) {
                        continue;
                    }

                    $this->sendEvent('room.occupancy.updated', [
                        'room_id' => $room->id,
                        'room_name' => $room->name,
                        'current_count' => $snapshot['current_count'],
                        'children' => $snapshot['children'] ?? [],
                    ], $this->formatEventCursor($lastMovementId, $lastAlertId));
                }
            }

            $alerts = Alert::query()
                ->select(['id', 'room_id', 'level', 'message', 'created_at', 'resolved_at'])
                ->where('id', '>', $lastAlertId)
                ->orderBy('id')
                ->limit(100)
                ->get();

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

        Log::channel('sse')->info('Dashboard SSE client disconnected', [
            'client_id' => $clientId,
            'cursor' => compact('lastMovementId', 'lastAlertId'),
        ]);
    }

    protected function runRoomStream(Request $request, int $roomId): void
    {
        $clientId = (string) Str::uuid();
        $this->prepareStream();
        [$lastMovementId, $lastAlertId] = $this->resolveStreamCursor($request);
        $startedAt = microtime(true);
        $maxDurationSeconds = (int) config('app.sse_max_connection_seconds', 60);
        $lastHeartbeatAt = microtime(true);
        $room = Room::query()->select(['id', 'name'])->findOrFail($roomId);

        Log::channel('sse')->info('Room SSE client connected', [
            'client_id' => $clientId,
            'room_id' => $roomId,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'cursor' => compact('lastMovementId', 'lastAlertId'),
        ]);

        $this->sendRetryInstruction();

        $initialSnapshot = $this->occupancySnapshotBuilder->forRoomIds([$roomId], true)->get($roomId, [
            'current_count' => 0,
            'children' => [],
        ]);
        $this->sendEvent('room.occupancy.updated', [
            'room_id' => $room->id,
            'room_name' => $room->name,
            'current_count' => $initialSnapshot['current_count'],
            'children' => $initialSnapshot['children'],
        ], $this->formatEventCursor($lastMovementId, $lastAlertId));
        $this->flushStream();

        while (! connection_aborted()) {
            $roomChanged = false;

            $movements = MovementLog::query()
                ->select(['id'])
                ->where('id', '>', $lastMovementId)
                ->where(function ($query) use ($roomId) {
                    $query->where('from_room_id', $roomId)
                        ->orWhere('to_room_id', $roomId);
                })
                ->orderBy('id')
                ->limit(100)
                ->get();

            foreach ($movements as $movement) {
                $lastMovementId = $movement->id;
                $roomChanged = true;
            }

            if ($roomChanged) {
                $snapshot = $this->occupancySnapshotBuilder->forRoomIds([$roomId], true)->get($roomId, [
                    'current_count' => 0,
                    'children' => [],
                ]);

                $this->sendEvent('room.occupancy.updated', [
                    'room_id' => $room->id,
                    'room_name' => $room->name,
                    'current_count' => $snapshot['current_count'],
                    'children' => $snapshot['children'],
                ], $this->formatEventCursor($lastMovementId, $lastAlertId));
            }

            $alerts = Alert::query()
                ->select(['id', 'room_id', 'level', 'message', 'created_at', 'resolved_at'])
                ->where('room_id', $roomId)
                ->where('id', '>', $lastAlertId)
                ->orderBy('id')
                ->limit(100)
                ->get();

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

        Log::channel('sse')->info('Room SSE client disconnected', [
            'client_id' => $clientId,
            'room_id' => $roomId,
            'cursor' => compact('lastMovementId', 'lastAlertId'),
        ]);
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
