<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Room\RoomStoreRequest;
use App\Http\Requests\Admin\Room\RoomUpdateRequest;
use App\Models\Room;
use App\Support\SseChangeSignal;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

class RoomAdminController extends Controller
{
    public function __construct(
        private readonly SseChangeSignal $sseChangeSignal,
    ) {
    }

    public function index(): JsonResponse
    {
        $rooms = Room::query()
            ->select(['id', 'name', 'area', 'capacity', 'tolerance', 'is_active'])
            ->orderBy('name')
            ->get();

        return response()->json($rooms);
    }

    public function store(RoomStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (! isset($data['tolerance'])) {
            $data['tolerance'] = 2;
        }

        $room = Room::create($data);

        // bumpChildren statt bump: neuer Raum hat keinen MovementLog, der SSE-Loop
        // wuerde ihn sonst erst beim naechsten Scan emittieren.
        $this->sseChangeSignal->bumpChildren();

        return response()->json($room, 201);
    }

    public function show(Room $room): JsonResponse
    {
        return response()->json($room);
    }

    public function update(RoomUpdateRequest $request, Room $room): JsonResponse
    {
        $room->fill($request->validated());
        $room->save();

        $this->sseChangeSignal->bump();

        return response()->json($room);
    }

    public function destroy(Room $room): JsonResponse
    {
        try {
            $room->delete();
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Room cannot be deleted because it is still in use.',
                'error' => $e->getCode(),
            ], 409);
        }

        $this->sseChangeSignal->bump();

        return response()->json([
            'message' => 'Room deleted',
        ]);
    }
}
