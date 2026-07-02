<?php

namespace App\Http\Requests\Admin\Child;

use App\Models\Child;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ChildUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => ['sometimes', 'required', 'string', 'max:100'],
            'photo_url'   => ['sometimes', 'nullable', 'url', 'max:255'],
            // Unique-Check in withValidator() (nennt das kollidierende Kind).
            // DB-Unique-Index bleibt als letzte Absicherung.
            'tracker_uid' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_active'   => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $uid = $this->input('tracker_uid');
            if (! is_string($uid) || trim($uid) === '') {
                return;
            }

            $child = $this->route('child');
            $currentId = $child instanceof Child ? $child->id : $child;

            $owner = Child::query()
                ->where('tracker_uid', trim($uid))
                ->where('id', '!=', $currentId)
                ->first();

            if ($owner) {
                $validator->errors()->add(
                    'tracker_uid',
                    "Dieser Tracker ist bereits \"{$owner->name}\" (#{$owner->id}) zugewiesen.",
                );
            }
        });
    }
}
