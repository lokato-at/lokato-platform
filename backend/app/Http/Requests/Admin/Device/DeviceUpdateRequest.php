<?php

namespace App\Http\Requests\Admin\Device;

use Illuminate\Foundation\Http\FormRequest;

class DeviceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $deviceId = $this->route('device');

        return [
            'name'    => ['sometimes', 'required', 'string', 'max:100'],
            'device_key' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                'unique:devices,device_key,' . $deviceId,
            ],
            'room_id' => ['sometimes', 'required', 'exists:rooms,id'],
        ];
    }
}
