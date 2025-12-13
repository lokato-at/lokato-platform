<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\ChildLocation;
use App\Models\MovementLog;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SseStreamController extends Controller
{
    public function dashboard(Request $request): StreamedResponse
    {
        return response()->stream(function () use ($request) {
            $this->runDashboardStream($request);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Run SSE loop for global dashboard.
     */
    protected function runDashboardStream(Request $request): void
    {
        $clientId = (string)Str::uuid();

        if (ob_get_level() > 0) {
            ob_end_flush();
        }

        // Ensure script is not limited by default time limit
        set_time_limit(0);
        ignore_user_abort(true);

        $lastMovementId = MovementLog::max('id') ?? 0;
        $lastAlertId = Alert::max('id') ?? 0;

        $loopCounter = 0;
        $heartbeatFrequency = 15; // send heartbeat comment every 15 loops

        Log::channel('sse')->info('Dashboard SSE client connected', [
            'client_id' => $clientId,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        while (!connection_aborted()) {
            $loopCounter++;
            $changedRoomIds = [];

            // 1) New movements
            $movements = MovementLog::query()
                ->where('id', '>', $lastMovementId)
                ->orderBy('id')
                ->limit(100)
                ->get();

            foreach ($movements as $movement) {
                $payload = [
                    'id' => $movement->id,
                    'child_id' => $movement->child_id,
                    'from_room_id' => $movement->from_room_id,
                    'to_room_id' => $movement->to_room_id,
                    'device_id' => $movement->device_id,
                    'source' => $movement->source,
                    'occurred_at' => optional($movement->occurred_at)->toIso8601String(),
                ];

                $this->sendEvent('child.moved', $payload, 'movement-' . $movement->id);
                $lastMovementId = $movement->id;

                if (!empty($movement->from_room_id)) {
                    $changedRoomIds[$movement->from_room_id] = true;
                }
                if (!empty($movement->to_room_id)) {
                    $changedRoomIds[$movement->to_room_id] = true;
                }
            }

            // 2) Occupancy updates for changed rooms (snapshot style)
            foreach (array_keys($changedRoomIds) as $roomId) {
                $room = Room::find($roomId);
                if (!$room) {
                    continue;
                }

                $children = ChildLocation::query()
                    ->with('child')
                    ->where('room_id', $roomId)
                    ->get()
                    ->map(function (ChildLocation $location) {
                        return [
                            'id' => $location->child->id,
                            'name' => $location->child->name,
                            'photo_url' => $location->child->photo_url,
                        ];
                    })
                    ->values()
                    ->all();

                $payload = [
                    'room_id' => $room->id,
                    'room_name' => $room->name,
                    'children' => $children,
                ];

                $this->sendEvent(
                    'room.occupancy.updated',
                    $payload,
                    'room-' . $room->id . '-' . now()->timestamp
                );
            }

            // 3) New alerts
            $alerts = Alert::query()
                ->where('id', '>', $lastAlertId)
                ->orderBy('id')
                ->limit(100)
                ->get();

            foreach ($alerts as $alert) {
                $payload = [
                    'id' => $alert->id,
                    'room_id' => $alert->room_id,
                    'level' => $alert->level,
                    'message' => $alert->message,
                    'created_at' => optional($alert->created_at)->toIso8601String(),
                    'resolved_at' => optional($alert->resolved_at)->toIso8601String(),
                ];

                $this->sendEvent('room.alert.raised', $payload, 'alert-' . $alert->id);
                $lastAlertId = $alert->id;
            }

            // 4) Heartbeat comment to keep connection alive
            if ($loopCounter % $heartbeatFrequency === 0) {
                echo ": heartbeat\n\n";
            }

            @ob_flush();
            flush();

            sleep(1);
        }

        Log::channel('sse')->info('Dashboard SSE client disconnected', [
            'client_id' => $clientId,
        ]);
    }

    /**
     * Send a single SSE event.
     */
    protected function sendEvent(string $eventName, array $data, ?string $id = null): void
    {
        if ($id !== null) {
            echo "id: {$id}\n";
        }

        echo "event: {$eventName}\n";
        echo 'data: ' . json_encode($data) . "\n\n";
    }

    public function room(Request $request, int $roomId): StreamedResponse
    {
        return response()->stream(function () use ($request, $roomId) {
            $this->runRoomStream($request, $roomId);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Run SSE loop for a single room.
     */
    protected function runRoomStream(Request $request, int $roomId): void
    {
        $clientId = (string)Str::uuid();

        if (ob_get_level() > 0) {
            ob_end_flush();
        }

        set_time_limit(0);
        ignore_user_abort(true);

        $room = Room::findOrFail($roomId);

        $lastMovementId = MovementLog::where(function ($q) use ($roomId) {
            $q->where('from_room_id', $roomId)
                ->orWhere('to_room_id', $roomId);
        })
            ->max('id') ?? 0;

        $lastAlertId = Alert::where('room_id', $roomId)->max('id') ?? 0;

        $loopCounter = 0;
        $heartbeatFrequency = 15;

        Log::channel('sse')->info('Room SSE client connected', [
            'client_id' => $clientId,
            'room_id' => $roomId,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        while (!connection_aborted()) {
            $loopCounter++;
            $roomChanged = false;

            // 1) New movements affecting this room
            $movements = MovementLog::query()
                ->where('id', '>', $lastMovementId)
                ->where(function ($q) use ($roomId) {
                    $q->where('from_room_id', $roomId)
                        ->orWhere('to_room_id', $roomId);
                })
                ->orderBy('id')
                ->limit(100)
                ->get();

            foreach ($movements as $movement) {
                $lastMovementId = $movement->id;
                $roomChanged = true;
            }

            // 2) If something changed → send occupancy snapshot for this room
            if ($roomChanged) {
                $children = ChildLocation::query()
                    ->with('child')
                    ->where('room_id', $roomId)
                    ->get()
                    ->map(function (ChildLocation $location) {
                        return [
                            'id' => $location->child->id,
                            'name' => $location->child->name,
                            'photo_url' => $location->child->photo_url,
                        ];
                    })
                    ->values()
                    ->all();

                $payload = [
                    'room_id' => $room->id,
                    'room_name' => $room->name,
                    'children' => $children,
                ];

                $this->sendEvent(
                    'room.occupancy.updated',
                    $payload,
                    'room-' . $room->id . '-' . now()->timestamp
                );
            }

            // 3) New alerts for this room
            $alerts = Alert::query()
                ->where('room_id', $roomId)
                ->where('id', '>', $lastAlertId)
                ->orderBy('id')
                ->limit(100)
                ->get();

            foreach ($alerts as $alert) {
                $payload = [
                    'id' => $alert->id,
                    'room_id' => $alert->room_id,
                    'level' => $alert->level,
                    'message' => $alert->message,
                    'created_at' => optional($alert->created_at)->toIso8601String(),
                    'resolved_at' => optional($alert->resolved_at)->toIso8601String(),
                ];

                $this->sendEvent('room.alert.raised', $payload, 'alert-' . $alert->id);
                $lastAlertId = $alert->id;
            }

            if ($loopCounter % $heartbeatFrequency === 0) {
                echo ": heartbeat\n\n";
            }

            @ob_flush();
            flush();

            sleep(1);
        }

        Log::channel('sse')->info('Room SSE client disconnected', [
            'client_id' => $clientId,
            'room_id' => $roomId,
        ]);
    }
}
