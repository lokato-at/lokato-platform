<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Device\DeviceStoreRequest;
use App\Http\Requests\Admin\Device\DeviceUpdateRequest;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class DeviceAdminController extends Controller
{
    public function index(): JsonResponse
    {
        $devices = Device::with('room')->orderBy('name')->get();

        return response()->json($devices);
    }

    public function store(DeviceStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Wenn kein api_key übergeben wird → generieren
        if (empty($data['api_key'])) {
            // 64 Zeichen, wie in deinem Schema
            $data['api_key'] = Str::random(64);
        }

        $device = Device::create($data);

        return response()->json($device, 201);
    }

    public function show(Device $device): JsonResponse
    {
        $device->load('room');

        return response()->json($device);
    }

    public function update(DeviceUpdateRequest $request, Device $device): JsonResponse
    {
        $data = $request->validated();

        $device->fill($data);
        $device->save();

        return response()->json($device);
    }

    public function destroy(Device $device): JsonResponse
    {
        $device->delete();

        return response()->json([
            'message' => 'Device deleted',
        ]);
    }
}
