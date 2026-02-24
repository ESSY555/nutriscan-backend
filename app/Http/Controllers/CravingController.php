<?php

namespace App\Http\Controllers;

use App\Models\CravingIdea;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'craving' => ['required', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'diet' => ['nullable', 'string', 'max:255'],
            'goal' => ['nullable', 'string', 'max:255'],
            'allergens' => ['nullable', 'array'],
            'allergens.*' => ['string', 'max:255'],
            'user_email' => ['nullable', 'string', 'email', 'max:255'],
        ]);

        $fingerprint = $this->fingerprint($data);
        $existing = CravingIdea::where('fingerprint', $fingerprint)->first();
        if ($existing && $existing->ideas_text) {
            return response()->json([
                'ideas' => $existing->ideas_text,
                'cached' => true,
            ]);
        }

        $ideas = $this->generateIdeasFromAi($data);

        $idea = CravingIdea::create([
            'user_email' => $data['user_email'] ?? null,
            'title' => 'AI Ideas',
            'note' => $ideas,
            'craving_prompt' => $data['craving'] ?? null,
            'country' => $data['country'] ?? null,
            'diet' => $data['diet'] ?? null,
            'goal' => $data['goal'] ?? null,
            'allergens' => $data['allergens'] ?? [],
            'fingerprint' => $fingerprint,
            'ideas_text' => $ideas,
        ]);

        return response()->json([
            'ideas' => $ideas,
            'cached' => false,
            'id' => $idea->id,
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

    private function fingerprint(array $data): string
    {
        $payload = [
            'craving' => trim($data['craving'] ?? ''),
            'country' => trim($data['country'] ?? ''),
            'diet' => trim($data['diet'] ?? ''),
            'goal' => trim($data['goal'] ?? ''),
            'allergens' => collect($data['allergens'] ?? [])
                ->map(fn ($a) => trim((string) $a))
                ->filter()
                ->sort()
                ->values()
                ->all(),
        ];
        return md5(json_encode($payload));
    }

    private function generateIdeasFromAi(array $data): string
    {
        $apiKey = env('OPENAI_API_KEY');
        if (!is_string($apiKey) || trim($apiKey) === '') {
            throw new \RuntimeException('AI unavailable (missing API key)');
        }

        $country = trim($data['country'] ?? '') ?: 'Any cuisine';
        $craving = trim($data['craving'] ?? '') ?: 'chef choice';
        $diet = trim($data['diet'] ?? '') ?: 'No Preference';
        $goal = trim($data['goal'] ?? '') ?: 'Eat Healthier';
        $allergens = collect($data['allergens'] ?? [])
            ->map(fn ($a) => trim((string) $a))
            ->filter()
            ->values();
        $allergenList = $allergens->isNotEmpty() ? $allergens->join(', ') : 'None specified';

        $prompt = <<<PROMPT
You are a meal recommendation assistant.

Country: {$country}
Craving: {$craving}
Diet: {$diet}
Goal: {$goal}
Allergens to avoid: {$allergenList}

Return 3 concise dish ideas that:
- avoid the allergens,
- fit the diet and goal,
- are culturally relevant to the country.

Format as a short numbered list, one line per idea (e.g. "1) Dish — short note"). No markdown fences.
PROMPT;

        $start = microtime(true);
        try {
            $res = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
            ])->timeout(18)->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => 220,
                'temperature' => 0.6,
            ]);
        } catch (\Throwable $e) {
            Log::error('Craving AI request failed', [
                'error' => $e->getMessage(),
                'ms' => (int) ((microtime(true) - $start) * 1000),
            ]);
            throw $e;
        }

        if (!$res->ok()) {
            Log::error('Craving AI non-200', [
                'status' => $res->status(),
                'body' => Str::limit($res->body(), 400),
            ]);
            throw new \RuntimeException('Craving AI error: ' . $res->status());
        }

        $text = data_get($res->json(), 'choices.0.message.content');
        if (!is_string($text) || trim($text) === '') {
            throw new \RuntimeException('Craving AI returned empty content.');
        }

        return trim($text);
    }
}
