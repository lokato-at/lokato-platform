<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Child\ChildStoreRequest;
use App\Http\Requests\Admin\Child\ChildUpdateRequest;
use App\Models\Child;
use Illuminate\Http\JsonResponse;

class ChildAdminController extends Controller
{
    public function index(): JsonResponse
    {
        $children = Child::orderBy('name')->get();

        return response()->json($children);
    }

    public function store(ChildStoreRequest $request): JsonResponse
    {
        $child = Child::create($request->validated());

        return response()->json($child, 201);
    }

    public function show(Child $child): JsonResponse
    {
        return response()->json($child);
    }

    public function update(ChildUpdateRequest $request, Child $child): JsonResponse
    {
        $child->fill($request->validated());
        $child->save();

        return response()->json($child);
    }

    public function destroy(Child $child): JsonResponse
    {
        $child->delete();

        return response()->json([
            'message' => 'Child deleted',
        ]);
    }
}
