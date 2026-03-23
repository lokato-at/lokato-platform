<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Child;
use App\Models\Device;
use App\Models\Room;
use Illuminate\Http\JsonResponse;

class AdminSummaryController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'children_count' => Child::query()->count(),
            'rooms_count' => Room::query()->count(),
            'devices_count' => Device::query()->count(),
        ]);
    }
}
