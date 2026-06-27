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
use Illuminate\Support\Facades\DB;

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

            // Wenn der Admin ein Kind auf is_active=false setzt, behandeln wir
            // das semantisch wie einen Checkout: MovementLog-Eintrag + Standort
            // leeren. Das hat zwei Vorteile:
            //   1) Der bestehende SSE-Poll-Mechanismus (movement_log + occupancy
            //      refresh) feuert automatisch — Dashboard/Tablet sehen die
            //      Aenderung ohne F5.
            //   2) Die History zeigt "Kind X wurde um Y ausgetragen" mit
            //      from_room=alter Raum, to_room=null.
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

        // bump() reicht — der frische MovementLog (falls geschrieben) wird vom
        // SSE-Loop automatisch gefunden und emittet child.moved + occupancy.
        // bumpChildren() ist hier bewusst nicht aktiviert, weil's bei reinen
        // Metadaten-Aenderungen (Name, Foto) sonst Full-Refresh aller Raeume
        // triggern wuerde — das ist Overkill.
        $this->sseChangeSignal->bump();

        return response()->json($child);
    }

    public function destroy(Child $child): JsonResponse
    {
        $child->delete();

        $this->sseChangeSignal->bumpChildren();

        return response()->json([
            'message' => 'Child deleted',
        ]);
    }
}
