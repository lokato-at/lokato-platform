<?php

namespace App\Http\Requests\Admin\Child;

use App\Models\Child;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChildUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $child = $this->route('child');

        return [
            'name'        => ['sometimes', 'required', 'string', 'max:100'],
            'photo_url'   => ['sometimes', 'nullable', 'url', 'max:255'],
            'tracker_uid' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                Rule::unique('children', 'tracker_uid')->ignore(
                    $child instanceof Child ? $child->id : $child,
                    'id'
                ),
            ],
            'is_active'   => ['sometimes', 'boolean'],
        ];
    }
}
