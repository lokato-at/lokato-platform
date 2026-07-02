<?php

namespace App\Http\Requests\Admin\Room;

use Illuminate\Foundation\Http\FormRequest;

class RoomStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:100'],
            'area'      => ['nullable', 'string', 'max:50'],
            // Nur Dateiname/Pfad eines Bildes (public/room-icons) — kein Upload.
            'icon'      => ['nullable', 'string', 'max:100'],
            'capacity'  => ['required', 'integer', 'min:0'],
            'tolerance' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
