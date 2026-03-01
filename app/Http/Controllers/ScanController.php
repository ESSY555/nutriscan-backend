<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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

        $user = null;
        if (!empty($data['user_email'])) {
            $user = User::where('email', strtolower($data['user_email']))->first();
        }

        $allergens = $user?->allergens ?? [];
        $diet = $data['diet'] ?? 'No Preference';
        $goal = $data['goal'] ?? 'Eat Healthier';

        $sourceLines = !empty($ingredientLines) ? $ingredientLines : $rawLines;

        $ingredients = collect($sourceLines)->map(function ($name) use ($allergens) {
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

        $emptyNotice = empty($ingredients) ? 'No ingredient-like text was detected. Try a clearer label photo.' : null;

        return response()->json([
            'ingredients' => $ingredients,
            'ai_note' => $aiNote,
            'verdict' => $verdict,
            'summary' => $aiNote,
            'raw_text' => $rawLines,
            'notice' => $emptyNotice,
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
