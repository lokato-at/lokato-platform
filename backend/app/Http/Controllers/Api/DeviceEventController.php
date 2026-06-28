<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeviceScanRequest;
use App\Services\ScanIngestService;
use App\Support\SseChangeSignal;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class DeviceEventController extends Controller
{
    public function __construct(
        private readonly ScanIngestService $scanIngestService,
        private readonly SseChangeSignal $sseChangeSignal,
    ) {
    }

    public function store(DeviceScanRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->scanIngestService->ingestScan(
            deviceKey: $validated['device_key'],
            trackerUid: $validated['tracker_uid'],
            eventTimeIso: $validated['event_time'] ?? null,
            source: 'device',
            requestIp: $request->ip(),
        );

        // null = Device/Child nicht gefunden ODER Same-Room-Skip. In beiden Faellen
        // 200 + movement:null antworten, sonst retried Scanner-Firmware endlos.
        // Grund steht im "scan"-Log-Channel.
        if ($result === null) {
            return response()->json([
                'status' => 'ok',
                'movement' => null,
            ]);
        }

        $this->sseChangeSignal->bump();

        return response()->json([
            'status' => 'ok',
            'movement' => [
                'id' => $result->id,
                'child_id' => $result->child_id,
                'from_room_id' => $result->from_room_id,
                'to_room_id' => $result->to_room_id,
                'device_id' => $result->device_id,
                'source' => $result->source,
                'occurred_at' => $result->occurred_at?->toIso8601String(),
            ],
        ]);
    }
}
