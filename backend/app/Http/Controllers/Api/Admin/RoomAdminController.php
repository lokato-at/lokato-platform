<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Room\RoomStoreRequest;
use App\Http\Requests\Admin\Room\RoomUpdateRequest;
use App\Models\Room;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

class RoomAdminController extends Controller
{
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

        return response()->json([
            'message' => 'Room deleted',
        ]);
    }
}
