<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Child;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ChildPhotoController extends Controller
{
    public function upload(Request $request, Child $child): JsonResponse
    {
        $request->validate([
            'photo' => 'required|image|mimes:jpeg,png,jpg,webp|max:4096',
        ]);

        // Altes Bild löschen wenn es ein lokal gespeichertes war
        if ($child->photo_url) {
            $oldPath = str_replace('/storage/', 'public/', parse_url($child->photo_url, PHP_URL_PATH));
            if (Storage::exists($oldPath)) {
                Storage::delete($oldPath);
            }
        }

        $path = $request->file('photo')->store('children/photos', 'public');
        $url = Storage::disk('public')->url($path);

        $child->update(['photo_url' => $url]);

        return response()->json(['photo_url' => $url]);
    }
}
