<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Child;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChildrenController extends Controller
{
    /**
     * GET /api/v1/children
     * Liste aller Kinder mit aktuellem Standort
     */
    public function index(Request $request): JsonResponse
    {
        $query = Child::query()
            ->select(['id', 'name', 'photo_url', 'tracker_uid', 'is_active'])
            ->with([
                'location:child_id,room_id,updated_at',
                'location.room:id,name,area',
            ])
            ->orderBy('name');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('room_id')) {
            $roomId = $request->integer('room_id');
            $query->whereHas('location', fn ($locationQuery) => $locationQuery->where('room_id', $roomId));
        }

        $limit = min(max($request->integer('limit', 0), 0), 500);
        if ($limit > 0) {
            $query->limit($limit);
        }

        $children = $query->get()->map(fn (Child $child) => [
            'id' => $child->id,
            'name' => $child->name,
            'photo_url' => $child->photo_url,
            'tracker_uid' => $child->tracker_uid,
            'is_active' => $child->is_active,
            'location' => $child->location ? [
                'room_id' => $child->location->room_id,
                'room_name' => $child->location->room?->name,
                'area' => $child->location->room?->area,
                'updated_at' => $child->location->updated_at?->toIso8601String(),
            ] : null,
        ]);

        return response()->json($children);
    }

    /**
     * GET /api/v1/children/{child}
     */
    public function show(Child $child): JsonResponse
    {
        $child->load([
            'location:child_id,room_id,updated_at',
            'location.room:id,name,area',
        ]);

        return response()->json([
            'id' => $child->id,
            'name' => $child->name,
            'photo_url' => $child->photo_url,
            'tracker_uid' => $child->tracker_uid,
            'is_active' => $child->is_active,
            'location' => $child->location ? [
                'room_id' => $child->location->room_id,
                'room_name' => $child->location->room?->name,
                'area' => $child->location->room?->area,
                'updated_at' => $child->location->updated_at?->toIso8601String(),
            ] : null,
        ]);
    }
}
