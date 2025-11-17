<?php

namespace App\Http\Requests\Admin\Child;

use Illuminate\Foundation\Http\FormRequest;

class ChildUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $childId = $this->route('child'); // kommt aus {child} in der Route

        return [
            'name'        => ['sometimes', 'required', 'string', 'max:100'],
            'photo_url'   => ['sometimes', 'nullable', 'url', 'max:255'],
            'tracker_uid' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                'unique:children,tracker_uid,' . $childId,
            ],
            'is_active'   => ['sometimes', 'boolean'],
        ];
    }
}
