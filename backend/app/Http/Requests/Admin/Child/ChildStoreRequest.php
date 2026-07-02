<?php

namespace App\Http\Requests\Admin\Child;

use App\Models\Child;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            // optional: Kinder werden oft VOR der RFID-Zuweisung angelegt.
            // Unique-Check läuft in withValidator(), damit die Meldung das bereits
            // zugewiesene Kind namentlich nennt. Der DB-Unique-Index auf
            // children.tracker_uid bleibt als letzte Absicherung (Race).
            'tracker_uid' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $uid = $this->input('tracker_uid');
            if (! is_string($uid) || trim($uid) === '') {
                return;
            }

            $owner = Child::query()->where('tracker_uid', trim($uid))->first();
            if ($owner) {
                $validator->errors()->add(
                    'tracker_uid',
                    "Dieser Tracker ist bereits \"{$owner->name}\" (#{$owner->id}) zugewiesen.",
                );
            }
        });
    }
}
