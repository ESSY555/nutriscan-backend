<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
            'country' => ['nullable', 'string', 'max:120'],
            'allergies' => ['nullable', 'array'],
            'allergies.*' => ['string', 'max:255'],
        ]);

        $file = $request->file('image');
        $storedName = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('scan_uploads', $storedName, 'local');
        $absolutePath = Storage::disk('local')->path($path);
        if (! file_exists($absolutePath)) {
            throw ValidationException::withMessages([
                'image' => ['Uploaded image could not be read from storage.'],
            ]);
        }

        // Re-encode to JPEG to avoid format quirks and improve OCR on Windows
        try {
            $raw = file_get_contents($absolutePath);
            if ($raw !== false) {
                $img = @imagecreatefromstring($raw);
                if ($img !== false) {
                    imagejpeg($img, $absolutePath, 95);
                    imagedestroy($img);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Image re-encode skipped', ['error' => $e->getMessage()]);
        }

        try {
            $text = (new TesseractOCR($absolutePath))
                ->lang('eng')
                ->psm(6)
                ->oem(1)
                ->run();
        } catch (\Throwable $e) {
            Log::warning('Tesseract failed', ['error' => $e->getMessage()]);
            throw ValidationException::withMessages([
                'image' => ['OCR failed. Please try again with a clearer image or adjust the crop.'],
            ]);
        }

        $rawLines = collect(preg_split('/\r\n|\r|\n/', $text))
            ->flatMap(fn($line) => explode(',', $line))
            ->map(fn($line) => trim(preg_replace('/[^A-Za-z0-9\s\-]/', '', $line)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        // Ingredient-like filtering: keep short, alpha-heavy lines
        $ingredientLines = collect($rawLines)->filter(function ($line) {
            $len = strlen($line);
            if ($len < 2 || $len > 50) return false;
            if (preg_match('/https?:\/\//i', $line)) return false;
            // Must contain letters and at most 6 words
            if (!preg_match('/[a-z]/i', $line)) return false;
            $wordCount = str_word_count($line);
            if ($wordCount === 0 || $wordCount > 6) return false;
            return true;
        })->values()->all();

        $profile = $this->resolveProfile(
            $data['user_email'] ?? null,
            [
                'country' => $data['country'] ?? null,
                'diet' => $data['diet'] ?? null,
                'goal' => $data['goal'] ?? null,
                'allergies' => $data['allergies'] ?? null,
            ]
        );

        $sourceLines = !empty($ingredientLines) ? $ingredientLines : $rawLines;
        $ingredients = $this->mapIngredientsWithSafety($sourceLines, $profile['allergens']);
        $ingredientHash = $this->hashIngredients(array_column($ingredients, 'name'));

        $emptyNotice = empty($ingredients) ? 'No ingredient-like text was detected. Try a clearer label photo.' : null;

        return response()->json([
            'ingredients' => $ingredients,
            'raw_text' => $rawLines,
            'notice' => $emptyNotice,
            'ingredient_hash' => $ingredientHash,
            'profile' => $profile,
        ]);
    }

    public function analyze(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ingredients' => ['required', 'array', 'min:1'],
            'ingredients.*' => ['string', 'max:255'],
            'product_name' => ['nullable', 'string', 'max:120'],
            'user_email' => ['nullable', 'email'],
            'ingredient_hash' => ['nullable', 'string', 'max:120'],
            'user_profile' => ['nullable', 'array'],
            'user_profile.country' => ['nullable', 'string', 'max:120'],
            'user_profile.allergies' => ['nullable', 'array'],
            'user_profile.allergies.*' => ['string', 'max:255'],
            'user_profile.diet' => ['nullable', 'string', 'max:120'],
            'user_profile.health_goal' => ['nullable', 'string', 'max:120'],
        ]);

        $profile = $this->resolveProfile(
            $data['user_email'] ?? null,
            [
                'country' => $data['user_profile']['country'] ?? null,
                'diet' => $data['user_profile']['diet'] ?? null,
                'goal' => $data['user_profile']['health_goal'] ?? null,
                'allergies' => $data['user_profile']['allergies'] ?? null,
            ]
        );

        $cleanIngredientNames = collect($data['ingredients'])
            ->map(fn($i) => trim(preg_replace('/[^A-Za-z0-9\s\-]/', '', (string) $i)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($cleanIngredientNames)) {
            throw ValidationException::withMessages([
                'ingredients' => ['Provide at least one ingredient to analyze.'],
            ]);
        }

        $productName = trim((string) ($data['product_name'] ?? ''));
        $productName = $productName !== '' ? $productName : 'Scanned Food';
        $ingredientHash = $data['ingredient_hash'] ?? $this->hashIngredients($cleanIngredientNames);

        $ingredients = $this->mapIngredientsWithSafety($cleanIngredientNames, $profile['allergens']);

        $cacheKey = 'scan_ai:' . md5(strtolower($productName) . '|' . $ingredientHash);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return response()->json(array_merge($cached, ['cached' => true]));
        }

        $ai = $this->buildAiAnalysis($cleanIngredientNames, $profile, $productName);
        $verdict = $this->mapVerdict($ai['verdict'] ?? null);

        $payload = [
            'ai' => $ai,
            'verdict' => $verdict,
            'ingredients' => $ingredients,
            'ingredient_hash' => $ingredientHash,
            'product_name' => $productName,
            'profile' => $profile,
            'cached' => false,
        ];

        Cache::put($cacheKey, $payload, now()->addDays(7));

        return response()->json($payload);
    }

    private function resolveProfile(?string $email, array $overrides = []): array
    {
        $user = null;
        if (!empty($email)) {
            $user = User::where('email', strtolower($email))->first();
        }

        $allergens = $overrides['allergies'] ?? $user?->allergens ?? [];
        $allergens = array_values(array_filter(array_map(fn($a) => trim((string) $a), $allergens)));

        $country = $overrides['country'] ?? ($user->country ?? null);
        $diet = $overrides['diet'] ?? ($user->diet_preference ?? 'No Preference');
        $goal = $overrides['goal'] ?? ($user->health_goal ?? 'Eat Healthier');

        return [
            'country' => $country ?: null,
            'allergens' => $allergens,
            'diet' => $diet ?: 'No Preference',
            'health_goal' => $goal ?: 'Eat Healthier',
        ];
    }

    private function mapIngredientsWithSafety(array $ingredientNames, array $allergens): array
    {
        return collect($ingredientNames)->map(function ($name) use ($allergens) {
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
    }

    private function hashIngredients(array $ingredientNames): string
    {
        $normalized = collect($ingredientNames)
            ->map(fn($i) => strtolower(trim((string) $i)))
            ->filter()
            ->values()
            ->all();

        return hash('sha256', json_encode($normalized));
    }

    private function buildAiAnalysis(array $ingredients, array $profile, string $productName): array
    {
        $apiKey = env('OPENAI_API_KEY');
        if (!is_string($apiKey) || trim($apiKey) === '') {
            Log::warning('AI analysis skipped: missing OPENAI_API_KEY');
            return [
                'verdict' => 'Moderate',
                'reason' => 'AI disabled: missing API key',
                'allergy_warning' => null,
                'diet_conflict' => null,
                'health_goal_alignment' => 'Unknown',
                'recommendation' => 'Please configure OPENAI_API_KEY on the server.',
            ];
        }

        $payload = [
            'ingredients' => array_values($ingredients),
            'user_profile' => [
                'country' => $profile['country'],
                'allergies' => $profile['allergens'],
                'diet' => $profile['diet'],
                'health_goal' => $profile['health_goal'],
            ],
            'product_name' => $productName,
        ];

        $instruction = <<<PROMPT
You are a certified nutrition expert.

Analyze this food based on:
- Ingredients
- User country
- Food allergies
- Diet type
- Health goal

Return JSON only in this format:
{
  "verdict": "Good | Moderate | Bad",
  "reason": "short explanation",
  "allergy_warning": "if applicable or null",
  "diet_conflict": "if applicable or null",
  "health_goal_alignment": "Does it support the goal?",
  "recommendation": "clear advice"
}
PROMPT;

        try {
            $res = Http::withToken($apiKey)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        ['role' => 'user', 'content' => $instruction],
                        ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_SLASHES)],
                    ],
                    'max_tokens' => 250,
                    'temperature' => 0.4,
                ]);

            if ($res->failed()) {
                Log::warning('AI analysis request failed', ['status' => $res->status(), 'body' => $res->body()]);
                return $this->fallbackAi();
            }

            $raw = $res->json('choices.0.message.content');
            if (!is_string($raw)) {
                return $this->fallbackAi();
            }

            $decoded = json_decode(trim($raw), true);
            if (!is_array($decoded)) {
                return $this->fallbackAi($raw);
            }

            return [
                'verdict' => $decoded['verdict'] ?? 'Moderate',
                'reason' => $decoded['reason'] ?? null,
                'allergy_warning' => $decoded['allergy_warning'] ?? null,
                'diet_conflict' => $decoded['diet_conflict'] ?? null,
                'health_goal_alignment' => $decoded['health_goal_alignment'] ?? null,
                'recommendation' => $decoded['recommendation'] ?? null,
                'raw' => $raw,
            ];
        } catch (\Throwable $e) {
            Log::warning('AI analysis error', ['error' => $e->getMessage()]);
            return $this->fallbackAi();
        }
    }

    private function fallbackAi(?string $raw = null): array
    {
        return [
            'verdict' => 'Moderate',
            'reason' => 'Unable to fetch AI analysis right now.',
            'allergy_warning' => null,
            'diet_conflict' => null,
            'health_goal_alignment' => 'Unknown',
            'recommendation' => 'Review the ingredients manually and try again later.',
            'raw' => $raw,
        ];
    }

    private function mapVerdict(?string $label): string
    {
        $normalized = strtolower((string) $label);
        if (str_contains($normalized, 'good')) {
            return 'good';
        }
        if (str_contains($normalized, 'bad')) {
            return 'avoid';
        }
        if (str_contains($normalized, 'moderate')) {
            return 'okay';
        }
        return 'okay';
    }
}
