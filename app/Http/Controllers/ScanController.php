<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use thiagoalessio\TesseractOCR\TesseractOCR;

class ScanController extends Controller
{
    public function extract(Request $request): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'image', 'max:6144'],
            'user_email' => ['nullable', 'email'],
            'diet' => ['nullable', 'string', 'max:255'],
            'goal' => ['nullable', 'string', 'max:255'],
        ]);

        $path = $request->file('image')->store('scan_uploads');
        $absolutePath = Storage::path($path);
        if (! file_exists($absolutePath)) {
            throw ValidationException::withMessages([
                'image' => ['Uploaded image could not be read from storage.'],
            ]);
        }

        $text = (new TesseractOCR($absolutePath))
            ->lang('eng')
            ->run();

        $rawLines = collect(preg_split('/\r\n|\r|\n/', $text))
            ->flatMap(fn($line) => explode(',', $line))
            ->map(fn($line) => trim(preg_replace('/[^A-Za-z0-9\s\-]/', '', $line)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $user = null;
        if (!empty($data['user_email'])) {
            $user = User::where('email', strtolower($data['user_email']))->first();
        }

        $allergens = $user?->allergens ?? [];
        $diet = $data['diet'] ?? 'No Preference';
        $goal = $data['goal'] ?? 'Eat Healthier';

        $ingredients = collect($rawLines)->map(function ($name) use ($allergens) {
            $safe = true;
            foreach ($allergens as $allergen) {
                if ($allergen && stripos($name, $allergen) !== false) {
                    $safe = false;
                    break;
                }
            }
            return [
                'name' => $name,
                'safe' => $safe,
                'note' => $safe ? null : 'Matches your allergen list',
            ];
        })->values()->all();

        $aiNote = $this->buildAiNote($ingredients, $allergens, $diet, $goal);

        $verdict = 'okay';
        if ($aiNote && preg_match('/avoid/i', $aiNote)) {
            $verdict = 'avoid';
        } elseif ($aiNote && preg_match('/good|suitable|safe/i', $aiNote)) {
            $verdict = 'good';
        }

        return response()->json([
            'ingredients' => $ingredients,
            'ai_note' => $aiNote,
            'verdict' => $verdict,
            'summary' => $aiNote,
            'raw_text' => $rawLines,
        ]);
    }

    private function buildAiNote(array $ingredients, array $allergens, string $diet, string $goal): ?string
    {
        $apiKey = env('OPENAI_API_KEY');
        if (!is_string($apiKey) || trim($apiKey) === '') {
            Log::warning('AI note skipped: missing OPENAI_API_KEY');
            return null;
        }

        $ingredientsList = collect($ingredients)->pluck('name')->join(', ');
        $allergensList = collect($allergens)->join(', ') ?: 'None specified';

        $prompt = "You are a food and nutrition assistant. Consider ALL THREE: allergies, diet preference, and health goal.\n\n"
            ."ALLERGIES (avoid): {$allergensList}\n"
            ."DIET PREFERENCE: {$diet}\n"
            ."HEALTH GOAL: {$goal}\n"
            ."Meal ingredients: {$ingredientsList}\n\n"
            ."In 2-4 short sentences:\n"
            ."1) Call out key ingredients.\n"
            ."2) Check against allergies (warn if any), diet fit, and health goal alignment.\n"
            ."3) Give a clear verdict (GOOD/CAUTION/AVOID) and a brief consequence if unsuitable, or benefit if suitable.";

        try {
            $res = Http::withToken($apiKey)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => 200,
                    'temperature' => 0.6,
                ]);

            if ($res->failed()) {
                Log::warning('AI note request failed', ['status' => $res->status(), 'body' => $res->body()]);
                return null;
            }

            $content = $res->json('choices.0.message.content');
            return is_string($content) ? trim($content) : null;
        } catch (\Throwable $e) {
            Log::warning('AI note request error', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
