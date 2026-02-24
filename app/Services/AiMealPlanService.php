<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiMealPlanService
{
    /**
     * Day labels expected by the mobile client.
     *
     * @var array<int, string>
     */
    private array $dayKeys = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    private string $openAiUrl = 'https://api.openai.com/v1/chat/completions';
    private string $model = 'gpt-4o-mini';

    /**
     * Build a full weekly plan using OpenAI only.
     *
     * @param array{
     *   diet: string,
     *   goal: string,
     *   country: ?string,
     *   allergens: array<int, string>,
     *   portion: string
     * } $preferences
     * @return array<string, mixed>
     */
    public function generateWeeklyPlan(array $preferences): array
    {
        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $weekEnd = (clone $weekStart)->addDays(6);

        $payload = $this->callOpenAi($preferences, count($this->dayKeys));
        $days = $this->mapDays($payload['days'] ?? [], $weekStart);
        $daily = $this->buildDailyFromWeek($days);

        return [
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'days' => $days,
            'daily' => $daily,
        ];
    }

    /**
     * Generate only today's meals using OpenAI.
     *
     * @param array{
     *   diet: string,
     *   goal: string,
     *   country: ?string,
     *   allergens: array<int, string>,
     *   portion: string
     * } $preferences
     * @return array{breakfast: ?array, lunch: ?array, dinner: ?array}
     */
    public function generateDailyMeals(array $preferences): array
    {
        $todayMeals = $this->callOpenAi($preferences, 1);
        $dayMeals = $todayMeals['days'][0]['meals'] ?? [];
        return $this->mapMealsForDay($dayMeals, Carbon::now()->toDateString(), 'Today');
    }

    /**
     * @return array<string, mixed>
     */
    private function callOpenAi(array $preferences, int $dayCount): array
    {
        $apiKey = env('OPENAI_API_KEY');
        if (!is_string($apiKey) || trim($apiKey) === '') {
            Log::error('AI meal plan missing OPENAI_API_KEY');
            throw new \RuntimeException('AI meal planner unavailable (missing API key).');
        }

        $allergens = array_values(array_filter(array_map('trim', $preferences['allergens'] ?? [])));
        $diet = trim((string) ($preferences['diet'] ?? 'No Preference'));
        $goal = trim((string) ($preferences['goal'] ?? 'Eat Healthier'));
        $country = trim((string) ($preferences['country'] ?? ''));
        $portion = trim((string) ($preferences['portion'] ?? 'medium'));

        $system = 'You are a nutritionist that plans realistic meals. Return ONLY JSON with no fences, no commentary.';
        $user = [
            'preferences' => [
                'diet' => $diet,
                'goal' => $goal,
                'country' => $country ?: 'Any',
                'allergens' => $allergens,
                'portion' => $portion,
            ],
            'days' => $dayCount,
            'schema' => [
                'days' => [
                    [
                        'day' => 'Mon',
                        'meals' => [
                            'breakfast' => [
                                'name' => 'Chicken Salad',
                                'category' => 'Salad',
                                'area' => 'American',
                                'thumb' => 'https://example.com/img.jpg',
                            ],
                            'lunch' => [
                                'name' => '...',
                            ],
                            'dinner' => [
                                'name' => '...',
                            ],
                        ],
                    ],
                ],
            ],
            'rules' => [
                'avoid_allergens' => $allergens,
                'respect_diet' => $diet,
                'respect_goal' => $goal,
                'adapt_country' => $country ?: 'Any',
                'keep_portion_note' => $portion,
                'keep names short' => true,
            ],
            'output' => 'Return only JSON. Do not include markdown fences or explanations.',
        ];

        $start = microtime(true);
        Log::info('AI meal plan request start', [
            'days_requested' => $dayCount,
            'diet' => $diet,
            'goal' => $goal,
            'country' => $country ?: 'Any',
            'portion' => $portion,
            'allergen_count' => count($allergens),
        ]);
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
            ])
                ->connectTimeout(8)
                ->timeout(25)
                ->post($this->openAiUrl, [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => json_encode($user)],
                ],
                'temperature' => 0.6,
                // Higher cap to avoid truncated JSON for 7-day plans.
                'max_tokens' => 1200,
                'response_format' => ['type' => 'json_object'],
            ]);
        } catch (\Throwable $e) {
            $ms = (int) ((microtime(true) - $start) * 1000);
            Log::error('AI meal plan request failed', [
                'ms' => $ms,
                'days_requested' => $dayCount,
                'diet' => $diet,
                'goal' => $goal,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('AI meal planner failed: ' . $e->getMessage(), 0, $e);
        }

        $ms = (int) ((microtime(true) - $start) * 1000);

        if (!$response->ok()) {
            Log::error('AI meal plan non-200', [
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 500),
                'ms' => $ms,
                'days_requested' => $dayCount,
                'diet' => $diet,
                'goal' => $goal,
                'country' => $country ?: 'Any',
                'portion' => $portion,
                'allergen_count' => count($allergens),
            ]);
            throw new \RuntimeException('AI meal planner error: ' . $response->status());
        }

        $content = data_get($response->json(), 'choices.0.message.content');
        if (is_array($content)) {
            $json = $content;
        } else {
            if (!is_string($content) || trim($content) === '') {
                Log::error('AI meal plan empty content', ['ms' => $ms]);
                throw new \RuntimeException('AI meal planner returned empty content.');
            }

            $clean = $this->stripCodeFences($content);
            $json = $this->safeDecodeJson($clean);

            if (!is_array($json)) {
                // last-resort: try to locate first/last braces
                $first = strpos($clean, '{');
                $last = strrpos($clean, '}');
                if ($first !== false && $last !== false && $last > $first) {
                    $slice = substr($clean, $first, $last - $first + 1);
                    $json = $this->safeDecodeJson($slice);
                }
            }

            if (!is_array($json)) {
                Log::error('AI meal plan invalid JSON', [
                    'content_preview' => Str::limit($clean, 400),
                    'ms' => $ms,
                    'content_type' => gettype($content),
                ]);
                throw new \RuntimeException('AI meal planner returned invalid JSON.');
            }
        }

        Log::info('AI meal plan success', [
            'ms' => $ms,
            'days_received' => is_array($json['days'] ?? null) ? count($json['days']) : 0,
        ]);

        return $json;
    }

    /**
     * @param array<int, array<string, mixed>> $days
     * @return array<string, array<string, mixed>>
     */
    private function mapDays(array $days, Carbon $weekStart): array
    {
        $mapped = [];
        foreach ($this->dayKeys as $idx => $dayKey) {
            $rawDay = $days[$idx] ?? [];
            $date = (clone $weekStart)->addDays($idx)->toDateString();
            $mapped[$dayKey] = [
                'date' => $date,
                'meals' => $this->mapMealsForDay($rawDay['meals'] ?? [], $date, $dayKey),
            ];
        }
        return $mapped;
    }

    private function stripCodeFences(string $content): string
    {
        $trimmed = trim($content);
        if (Str::startsWith($trimmed, '```')) {
            $trimmed = preg_replace('/^```[a-zA-Z0-9]*\\s*/', '', $trimmed);
            $trimmed = preg_replace('/```\\s*$/', '', $trimmed);
        }
        return trim($trimmed ?? '');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeDecodeJson(string $content): ?array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $meals
     * @return array<string, mixed>
     */
    private function mapMealsForDay(array $meals, string $date, string $dayKey): array
    {
        $out = [];
        foreach (['breakfast', 'lunch', 'dinner'] as $slot) {
            $meal = $meals[$slot] ?? null;
            if (!is_array($meal) || empty($meal['name'])) {
                $out[$slot] = null;
                continue;
            }
            $name = trim((string) $meal['name']);
            $id = substr(sha1($name . $slot . $date . $dayKey), 0, 16);
            $out[$slot] = [
                'idMeal' => $id,
                'strMeal' => $name,
                'strCategory' => $meal['category'] ?? null,
                'strArea' => $meal['area'] ?? null,
                'strMealThumb' => $meal['thumb'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $days
     * @return array<string, mixed>
     */
    private function buildDailyFromWeek(array $days): array
    {
        $today = Carbon::now();
        $dayKey = $this->dayKeys[$today->dayOfWeekIso - 1] ?? 'Mon';
        $day = $days[$dayKey] ?? [
            'date' => $today->toDateString(),
            'meals' => ['breakfast' => null, 'lunch' => null, 'dinner' => null],
        ];

        return [
            'date' => $day['date'],
            'meals' => $day['meals'],
        ];
    }
}
