<?php

namespace App\Http\Requests\Admin\Device;

use Illuminate\Foundation\Http\FormRequest;

class DeviceStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'    => ['required', 'string', 'max:100'],
            'device_key' => ['required', 'string', 'max:100', 'unique:devices,device_key'],
            'room_id' => ['required', 'exists:rooms,id'],
        ];
    }
}
