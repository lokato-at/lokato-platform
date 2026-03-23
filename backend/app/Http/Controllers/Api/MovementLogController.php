<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MovementLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MovementLogController extends Controller
{
    /**
     * GET /api/v1/movement-log
     */
    public function index(Request $request): JsonResponse
    {
        $query = MovementLog::query()
            ->select(['id', 'child_id', 'from_room_id', 'to_room_id', 'device_id', 'source', 'occurred_at'])
            ->with([
                'child:id,name',
                'fromRoom:id,name',
                'toRoom:id,name',
                'device:id,name',
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if ($request->filled('child_id')) {
            $query->where('child_id', $request->integer('child_id'));
        }

        if ($request->filled('room_id')) {
            $roomId = $request->integer('room_id');
            $query->where(function ($movementQuery) use ($roomId) {
                $movementQuery->where('from_room_id', $roomId)
                    ->orWhere('to_room_id', $roomId);
            });
        }

        if ($request->filled('from')) {
            $query->where('occurred_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->where('occurred_at', '<=', $request->date('to'));
        }

        $perPage = min(max($request->integer('per_page', 50), 1), 200);
        $logs = $query->paginate($perPage);

        $logs->getCollection()->transform(fn (MovementLog $log) => [
            'id' => $log->id,
            'child' => [
                'id' => $log->child->id,
                'name' => $log->child->name,
            ],
            'from_room' => $log->fromRoom ? [
                'id' => $log->fromRoom->id,
                'name' => $log->fromRoom->name,
            ] : null,
            'to_room' => $log->toRoom ? [
                'id' => $log->toRoom->id,
                'name' => $log->toRoom->name,
            ] : null,
            'device' => $log->device ? [
                'id' => $log->device->id,
                'name' => $log->device->name,
            ] : null,
            'source' => $log->source,
            'occurred_at' => $log->occurred_at->toIso8601String(),
        ]);

        return response()->json($logs);
    }

    /**
     * GET /api/v1/children/{child}/movement-log
     */
    public function byChild(int $childId, Request $request): JsonResponse
    {
        $request->merge(['child_id' => $childId]);

        return $this->index($request);
    }
}
