<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Child\ChildStoreRequest;
use App\Http\Requests\Admin\Child\ChildUpdateRequest;
use App\Models\Child;
use App\Models\ChildLocation;
use App\Models\MovementLog;
use App\Support\SseChangeSignal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ChildAdminController extends Controller
{
    public function __construct(
        private readonly SseChangeSignal $sseChangeSignal,
    ) {
    }

    public function index(): JsonResponse
    {
        $children = Child::query()
            ->select(['id', 'name', 'photo_url', 'tracker_uid', 'is_active'])
            ->orderBy('name')
            ->get();

        return response()->json($children);
    }

    public function store(ChildStoreRequest $request): JsonResponse
    {
        $child = Child::create($request->validated());

        $this->sseChangeSignal->bumpChildren();

        return response()->json($child, 201);
    }

    public function show(Child $child): JsonResponse
    {
        return response()->json($child);
    }

    public function update(ChildUpdateRequest $request, Child $child): JsonResponse
    {
        $data = $request->validated();
        $wasActive = (bool) $child->is_active;
        $willBeInactive = array_key_exists('is_active', $data) && $data['is_active'] === false;

        DB::transaction(function () use ($child, $data, $wasActive, $willBeInactive) {
            $child->fill($data);
            $child->save();

            // Admin-Deaktivierung wird als Checkout behandelt: MovementLog + Standort
            // leeren, damit der SSE-Poll-Mechanismus die Aenderung mitkriegt und die
            // History den Auszug dokumentiert.
            if ($wasActive && $willBeInactive) {
                $loc = ChildLocation::query()->where('child_id', $child->id)->lockForUpdate()->first();
                if ($loc) {
                    MovementLog::create([
                        'child_id' => $child->id,
                        'from_room_id' => $loc->room_id,
                        'to_room_id' => null,
                        'device_id' => null,
                        'source' => 'manual',
                        'occurred_at' => now(),
                    ]);
                    $loc->delete();
                }
            }
        });

        $this->sseChangeSignal->bump();

        return response()->json($child);
    }

    public function destroy(Child $child): JsonResponse
    {
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            Storage::disk('public')->delete("children/{$child->id}.{$ext}");
        }

        $child->delete();

        $this->sseChangeSignal->bumpChildren();

        return response()->json([
            'message' => 'Child deleted',
        ]);
    }

    public function uploadPhoto(Request $request, Child $child): JsonResponse
    {
        $request->validate([
            'photo' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $file = $request->file('photo');

        // jpeg → jpg normalisieren, damit ChildPhoto-Fallback den Convention-Pfad findet.
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        if ($ext === 'jpeg') $ext = 'jpg';

        $relativePath = "children/{$child->id}.{$ext}";

        // Alte Datei mit abweichender Extension wegraeumen, sonst bleibt z.B. 5.png
        // liegen waehrend wir 5.jpg neu schreiben.
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $oldExt) {
            if ($oldExt === $ext) continue;
            Storage::disk('public')->delete("children/{$child->id}.{$oldExt}");
        }

        // move() statt Storage::putFileAs(): letzteres hatte im Container-Setup
        // silent failures (200-Response, aber Datei nicht geschrieben).
        $destDir = Storage::disk('public')->path('children');
        if (! is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }
        $file->move($destDir, "{$child->id}.{$ext}");

        $publicUrl = "/storage/{$relativePath}";
        $child->photo_url = $publicUrl;
        $child->save();

        $this->sseChangeSignal->bumpChildren();

        return response()->json([
            'id' => $child->id,
            'photo_url' => $publicUrl,
        ]);
    }

    public function deletePhoto(Child $child): JsonResponse
    {
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            Storage::disk('public')->delete("children/{$child->id}.{$ext}");
        }

        $child->photo_url = null;
        $child->save();

        $this->sseChangeSignal->bumpChildren();

        return response()->json(['id' => $child->id, 'photo_url' => null]);
    }
}
