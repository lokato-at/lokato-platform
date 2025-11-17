<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeviceScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // optional: IP-Whitelist o.Ä.
        return true;
    }

    public function rules(): array
    {
        return [
            'api_key'     => ['required', 'string', 'size:64'],
            'tracker_uid' => ['required', 'string', 'max:100'],
            'event_time'  => ['nullable', 'date'], // ISO8601 oder 'Y-m-d H:i:s'
        ];
    }
}
