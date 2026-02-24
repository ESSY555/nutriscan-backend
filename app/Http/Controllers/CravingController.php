<?php

namespace App\Http\Controllers;

use App\Models\CravingIdea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CravingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_email' => ['nullable', 'string', 'email', 'max:255'],
        ]);

        $query = CravingIdea::query()->orderByDesc('created_at');
        if (!empty($data['user_email'])) {
            $query->where('user_email', $data['user_email']);
        }

        return response()->json([
            'ideas' => $query->limit(50)->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'user_email' => ['nullable', 'string', 'email', 'max:255'],
            'craving_prompt' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'diet' => ['nullable', 'string', 'max:255'],
            'goal' => ['nullable', 'string', 'max:255'],
        ]);

        $idea = CravingIdea::create($data);

        return response()->json([
            'message' => 'Saved',
            'idea' => $idea,
        ], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $idea = CravingIdea::find($id);
        if (!$idea) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $idea->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
