<?php

namespace App\Http\Requests\Admin\Device;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeviceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $device = $this->route('device'); // Model oder ID
        $deviceId = $device instanceof Device ? $device->id : $device;

        return [
            'name'    => ['sometimes', 'required', 'string', 'max:100'],
            'device_key' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('devices', 'device_key')->ignore($deviceId, 'id'),
            ],
            'room_id' => ['sometimes', 'required', 'exists:rooms,id'],
        ];
    }
}
