<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Device\DeviceStoreRequest;
use App\Http\Requests\Admin\Device\DeviceUpdateRequest;
use App\Models\Device;
use App\Support\SseChangeSignal;
use Illuminate\Http\JsonResponse;

class DeviceAdminController extends Controller
{
    public function __construct(
        private readonly SseChangeSignal $sseChangeSignal,
    ) {
    }

    public function index(): JsonResponse
    {
        $devices = Device::query()
            ->select(['id', 'name', 'device_key', 'room_id', 'last_seen'])
            ->with(['room:id,name'])
            ->orderBy('name')
            ->get();

        return response()->json($devices);
    }

    public function store(DeviceStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        $device = Device::create($data);

        $this->sseChangeSignal->bumpChildren();

        return response()->json($device, 201);
    }

    public function show(Device $device): JsonResponse
    {
        $device->load('room:id,name');

        return response()->json($device);
    }

    public function update(DeviceUpdateRequest $request, Device $device): JsonResponse
    {
        $data = $request->validated();

        $device->fill($data);
        $device->save();

        $this->sseChangeSignal->bumpChildren();

        return response()->json($device);
    }

    public function destroy(Device $device): JsonResponse
    {
        $device->delete();

        $this->sseChangeSignal->bumpChildren();

        return response()->json([
            'message' => 'Device deleted',
        ]);
    }
}
