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
     * Query-Parameter:
     * - child_id (optional)
     * - room_id (optional, filter to_room_id)
     * - from (optional, ISO-Datum)
     * - to (optional, ISO-Datum)
     * - limit (optional, default 100)
     */
    public function index(Request $request): JsonResponse
    {
        $query = MovementLog::query()
            ->with(['child', 'fromRoom', 'toRoom', 'device'])
            ->orderByDesc('occurred_at');

        if ($request->filled('child_id')) {
            $query->where('child_id', $request->integer('child_id'));
        }

        if ($request->filled('room_id')) {
            $query->where('to_room_id', $request->integer('room_id'));
        }

        if ($request->filled('from')) {
            $query->where('occurred_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->where('occurred_at', '<=', $request->date('to'));
        }

        $limit = min($request->integer('limit', 100), 1000);

        $logs = $query->limit($limit)->get()->map(function (MovementLog $log) {
            return [
                'id'           => $log->id,
                'child'        => [
                    'id'   => $log->child->id,
                    'name' => $log->child->name,
                ],
                'from_room'    => $log->fromRoom ? [
                    'id'   => $log->fromRoom->id,
                    'name' => $log->fromRoom->name,
                ] : null,
                'to_room'      => $log->toRoom ? [
                    'id'   => $log->toRoom->id,
                    'name' => $log->toRoom->name,
                ] : null,
                'device'       => $log->device ? [
                    'id'   => $log->device->id,
                    'name' => $log->device->name,
                ] : null,
                'source'       => $log->source,
                'occurred_at'  => $log->occurred_at->toIso8601String(),
            ];
        });

        return response()->json($logs);
    }

    /**
     * GET /api/v1/children/{child}/movement-log
     * Historie für ein bestimmtes Kind
     */
    public function byChild(int $childId, Request $request): JsonResponse
    {
        $request->merge(['child_id' => $childId]);
        return $this->index($request);
    }
}
