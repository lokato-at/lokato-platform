<?php

namespace App\Http\Requests\Admin\Child;

use Illuminate\Foundation\Http\FormRequest;

class ChildStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        // hier später Admin-Check einbauen
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'photo_url' => ['nullable', 'url', 'max:255'],
            'tracker_uid' => ['required', 'string', 'max:100', 'unique:children,tracker_uid'],
            'is_active' => ['boolean'],
        ];
    }
}
