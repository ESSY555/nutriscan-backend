<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NutritionTipController extends Controller
{
    public function tip(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country' => ['nullable', 'string', 'max:255'],
            'diet' => ['nullable', 'string', 'max:255'],
            'goal' => ['nullable', 'string', 'max:255'],
            'portion' => ['nullable', 'string', 'max:255'],
            'allergens' => ['nullable', 'array'],
            'allergens.*' => ['string', 'max:255'],
        ]);

        $apiKey = env('OPENAI_API_KEY');
        if (!is_string($apiKey) || trim($apiKey) === '') {
            return response()->json(['tip' => null, 'error' => 'OpenAI key missing'], 503);
        }

        $country = trim($data['country'] ?? '') ?: 'Any cuisine';
        $diet = trim($data['diet'] ?? '') ?: 'No Preference';
        $goal = trim($data['goal'] ?? '') ?: 'Eat Healthier';
        $portion = trim($data['portion'] ?? '') ?: 'medium';
        $allergens = array_values(array_filter(array_map('trim', $data['allergens'] ?? [])));
        $allergenList = $allergens ? implode(', ', $allergens) : 'None specified';

        $prompt = <<<TXT
You are a concise nutrition coach.

User country: {$country}
Diet preference: {$diet}
Health goal: {$goal}
Portion size: {$portion}
Allergens to avoid: {$allergenList}

Give ONE short tip (1-2 sentences, under 35 words) that:
- Matches the country context when relevant,
- Avoids allergens,
- Supports the diet and goal,
- Mentions portion sizing if helpful.

No emojis or bullet points.
TXT;

        try {
            $res = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
            ])->timeout(15)->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'max_tokens' => 80,
                'temperature' => 0.6,
            ]);

            if (!$res->ok()) {
                Log::warning('Nutrition tip non-200', ['status' => $res->status(), 'body' => $res->body()]);
                return response()->json(['tip' => null, 'error' => 'tip_unavailable'], 502);
            }

            $text = data_get($res->json(), 'choices.0.message.content');
            $tip = is_string($text) ? trim($text) : null;
            return response()->json(['tip' => $tip, 'error' => null]);
        } catch (\Throwable $e) {
            Log::warning('Nutrition tip error', ['error' => $e->getMessage()]);
            return response()->json(['tip' => null, 'error' => 'tip_error'], 502);
        }
    }
}
