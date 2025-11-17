<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Child;
use Illuminate\Http\JsonResponse;

class ChildrenController extends Controller
{
    /**
     * GET /api/v1/children
     * Liste aller Kinder mit aktuellem Standort
     */
    public function index(): JsonResponse
    {
        $children = Child::with(['location.room'])
            ->orderBy('name')
            ->get()
            ->map(function (Child $child) {
                return [
                    'id'         => $child->id,
                    'name'       => $child->name,
                    'photo_url'  => $child->photo_url,
                    'tracker_uid'=> $child->tracker_uid,
                    'is_active'  => $child->is_active,
                    'location'   => $child->location ? [
                        'room_id'    => $child->location->room_id,
                        'room_name'  => $child->location->room?->name,
                        'area'       => $child->location->room?->area,
                        'updated_at' => optional($child->location->updated_at)->toIso8601String(),
                    ] : null,
                ];
            });

        return response()->json($children);
    }

    /**
     * GET /api/v1/children/{child}
     */
    public function show(Child $child): JsonResponse
    {
        $child->load(['location.room']);

        return response()->json([
            'id'         => $child->id,
            'name'       => $child->name,
            'photo_url'  => $child->photo_url,
            'tracker_uid'=> $child->tracker_uid,
            'is_active'  => $child->is_active,
            'location'   => $child->location ? [
                'room_id'    => $child->location->room_id,
                'room_name'  => $child->location->room?->name,
                'area'       => $child->location->room?->area,
                'updated_at' => optional($child->location->updated_at)->toIso8601String(),
            ] : null,
        ]);
    }
}
