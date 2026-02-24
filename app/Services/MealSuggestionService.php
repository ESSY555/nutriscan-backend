<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MealSuggestionService
{
    private string $base;
    private int $timeoutSeconds = 8;
    private int $areaSubsetLimit = 8;

    private array $countryAreaMap = [
        'usa' => 'American',
        'unitedstates' => 'American',
        'unitedstatesofamerica' => 'American',
        'nigeria' => 'Nigerian',
        'ghana' => 'Ghanaian',
        'mexico' => 'Mexican',
        'canada' => 'Canadian',
        'brazil' => 'Brazilian',
        'jamaica' => 'Jamaican',
        'china' => 'Chinese',
        'japan' => 'Japanese',
        'india' => 'Indian',
        'pakistan' => 'Indian',
        'bangladesh' => 'Indian',
        'france' => 'French',
        'spain' => 'Spanish',
        'italy' => 'Italian',
        'germany' => 'German',
        'croatia' => 'Croatian',
        'thailand' => 'Thai',
        'vietnam' => 'Vietnamese',
        'philippines' => 'Philippine',
        'egypt' => 'Egyptian',
        'greece' => 'Greek',
        'ireland' => 'Irish',
        'malaysia' => 'Malaysian',
        'russia' => 'Russian',
        'scotland' => 'Scottish',
        'turkey' => 'Turkish',
        'uk' => 'British',
        'unitedkingdom' => 'British',
        'england' => 'British',
    ];

    private array $countryAliases = [
        'nigeria' => ['naija', 'ng'],
        'usa' => ['us', 'america', 'american'],
        'uk' => ['england', 'britain'],
    ];

    public function __construct()
    {
        $this->base = rtrim((string) env('MEAL_API_BASE', 'https://www.themealdb.com/api/json/v1/1'), '/');
    }

    /**
     * Suggest meals for a given context (breakfast, lunch, dinner).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchMealsForContext(string $diet, array $allergens, string $timeContext, ?string $country, ?string $goal): array
    {
        $logContext = [
            'diet' => $diet,
            'allergens_count' => count($allergens),
            'time' => $timeContext,
            'country' => $country,
            'goal' => $goal,
        ];

        $baseList = [];

        $countryMeals = $this->fetchMealsForCountry($country);
        if (count($countryMeals)) {
            $baseList = $countryMeals;
        }

        $category = $this->pickCategoryForTime($diet, $timeContext);
        if (!count($baseList) && $category) {
            $baseList = $this->fetchMealsByCategory($category);
        }

        if (!count($baseList)) {
            $baseList = $this->fetchUniversalMeals(24);
        }

        $ranked = $this->rankByGoal($baseList, $goal);
        $shuffled = $this->shuffle($ranked);

        if (empty($allergens)) {
            return array_slice($shuffled, 0, 12);
        }

        $toCheck = array_slice($shuffled, 0, 12);
        $safe = [];
        foreach ($toCheck as $meal) {
            if (!isset($meal['idMeal'])) {
                continue;
            }
            $detail = $this->fetchMealDetail((string) $meal['idMeal']);
            if ($detail && !$this->mealContainsAllergens($detail, $allergens)) {
                $safe[] = $meal;
            }
            if (count($safe) >= 12) {
                break;
            }
        }

        $final = count($safe) ? $safe : array_slice($shuffled, 0, 12);

        Log::info('Meal suggestions built', array_merge($logContext, [
            'base_count' => count($baseList),
            'final_count' => count($final),
            'used_allergen_filter' => !empty($allergens),
        ]));

        if (count($final) === 0) {
            Log::warning('Meal suggestions empty', $logContext);
        }

        return $final;
    }

    private function pickCategoryForTime(string $diet, string $timeContext): ?string
    {
        $d = trim($diet ?: 'No Preference');
        if ($d === 'Vegetarian') {
            return 'Vegetarian';
        }
        if ($d === 'Vegan') {
            return 'Vegan';
        }
        if ($timeContext === 'breakfast') {
            return 'Breakfast';
        }
        if ($d === 'Pescatarian') {
            return 'Seafood';
        }
        return null;
    }

    private function fetchMealsByCategory(string $category): array
    {
        $data = $this->fetchJson('/filter.php', ['c' => $category]);
        $meals = $data['meals'] ?? [];
        return is_array($meals) ? $meals : [];
    }

    private function fetchMealsForCountry(?string $country): array
    {
        $norm = $this->normalizeCountry($country);
        if ($norm === '') {
            return [];
        }

        $mapped = $this->countryAreaMap[$norm] ?? null;
        if (!$mapped) {
            foreach ($this->countryAliases as $key => $aliases) {
                if (in_array($norm, $aliases, true)) {
                    $mapped = $this->countryAreaMap[$key] ?? $key;
                    break;
                }
            }
        }

        $results = [];
        foreach ([$country, $mapped] as $area) {
            if (!$area) {
                continue;
            }
            $meals = $this->fetchByArea($area);
            if (count($meals)) {
                $results = array_merge($results, $meals);
            }
        }

        if (!count($results)) {
            $areas = $this->fetchAreas();
            $match = collect($areas)->first(function ($a) use ($norm) {
                return Str::startsWith(Str::lower((string) $a), Str::substr($norm, 0, 4));
            });
            if ($match) {
                $results = $this->fetchByArea($match);
            }
        }

        if (!count($results)) {
            $searchMeals = $this->fetchByNameSearch($country ?? '');
            if (count($searchMeals)) {
                $results = $searchMeals;
            }
        }

        return $this->dedupeMeals($results);
    }

    private function fetchByArea(string $area): array
    {
        $data = $this->fetchJson('/filter.php', ['a' => $area]);
        return is_array($data['meals'] ?? null) ? $data['meals'] : [];
    }

    private function fetchAreas(): array
    {
        $data = $this->fetchJson('/list.php', ['a' => 'list']);
        $areas = $data['meals'] ?? [];
        return collect($areas)
            ->map(fn ($item) => $item['strArea'] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    private function fetchByNameSearch(string $term): array
    {
        $data = $this->fetchJson('/search.php', ['s' => $term]);
        return is_array($data['meals'] ?? null) ? $data['meals'] : [];
    }

    private function fetchUniversalMeals(int $poolSize = 30): array
    {
        $areas = $this->fetchAreas();
        $shuffled = $this->shuffle($areas);
        $limited = array_slice($shuffled, 0, $this->areaSubsetLimit);

        $allMeals = [];
        foreach ($limited as $area) {
            $data = $this->fetchJson('/filter.php', ['a' => $area]);
            if (isset($data['meals']) && is_array($data['meals'])) {
                $allMeals = array_merge($allMeals, $data['meals']);
            }
        }

        return array_slice($this->shuffle($allMeals), 0, $poolSize);
    }

    private function fetchMealDetail(string $id): ?array
    {
        $data = $this->fetchJson('/lookup.php', ['i' => $id]);
        $meal = $data['meals'][0] ?? null;
        return is_array($meal) ? $meal : null;
    }

    private function mealContainsAllergens(array $detail, array $allergens): bool
    {
        $ingredients = $this->getIngredients($detail);
        $allergenLower = collect($allergens)
            ->map(fn ($a) => Str::lower(trim((string) $a)))
            ->filter()
            ->values()
            ->all();

        foreach ($ingredients as $ing) {
            $ingLower = Str::lower($ing['name']);
            foreach ($allergenLower as $all) {
                if (Str::contains($ingLower, $all) || Str::contains($all, $ingLower)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function getIngredients(array $detail): array
    {
        $out = [];
        for ($i = 1; $i <= 15; $i++) {
            $name = $detail["strIngredient{$i}"] ?? null;
            $measure = $detail["strMeasure{$i}"] ?? '';
            if ($name && trim((string) $name) !== '') {
                $out[] = [
                    'name' => trim((string) $name),
                    'measure' => trim((string) $measure),
                ];
            }
        }
        return $out;
    }

    private function rankByGoal(array $meals, ?string $goal): array
    {
        $goalNormalized = Str::lower((string) $goal);
        if ($goalNormalized === '') {
            return $meals;
        }

        $positiveKeywordsWeight = ['salad', 'soup', 'grill', 'fish', 'shrimp', 'veggie'];
        $positiveCategoriesWeight = ['Seafood', 'Chicken', 'Vegetarian', 'Vegan'];
        $negativeCategoriesWeight = ['Dessert', 'Pork', 'Beef', 'Lamb'];

        $positiveKeywordsMuscle = ['beef', 'chicken', 'steak', 'lamb', 'egg', 'protein'];
        $positiveCategoriesMuscle = ['Beef', 'Chicken', 'Lamb', 'Goat', 'Pork', 'Seafood'];

        $demoteDessert = ['Dessert'];

        $preferWeightLoss = Str::contains($goalNormalized, ['weight', 'loss']);
        $preferMuscle = Str::contains($goalNormalized, ['muscle', 'gain', 'bulk']);
        $preferHealth = Str::contains($goalNormalized, ['health', 'fit']);

        $score = function (array $meal) use (
            $preferWeightLoss,
            $preferMuscle,
            $preferHealth,
            $positiveCategoriesWeight,
            $negativeCategoriesWeight,
            $positiveKeywordsWeight,
            $positiveCategoriesMuscle,
            $positiveKeywordsMuscle,
            $demoteDessert
        ) {
            $s = 0;
            $name = Str::lower((string) ($meal['strMeal'] ?? ''));
            $cat = (string) ($meal['strCategory'] ?? '');

            if ($preferWeightLoss) {
                if (in_array($cat, $positiveCategoriesWeight, true)) {
                    $s += 3;
                }
                if (in_array($cat, $negativeCategoriesWeight, true)) {
                    $s -= 2;
                }
                foreach ($positiveKeywordsWeight as $k) {
                    if (Str::contains($name, $k)) {
                        $s += 2;
                    }
                }
            }

            if ($preferMuscle) {
                if (in_array($cat, $positiveCategoriesMuscle, true)) {
                    $s += 3;
                }
                foreach ($positiveKeywordsMuscle as $k) {
                    if (Str::contains($name, $k)) {
                        $s += 2;
                    }
                }
            }

            if ($preferHealth) {
                if (in_array($cat, $demoteDessert, true)) {
                    $s -= 3;
                }
                if (Str::contains($name, ['salad', 'grill'])) {
                    $s += 2;
                }
            }

            return $s;
        };

        return collect($meals)
            ->sortByDesc(fn ($m) => $score($m))
            ->values()
            ->all();
    }

    private function fetchJson(string $path, array $query = []): array
    {
        $start = microtime(true);
        $url = $this->base . $path;
        try {
            $response = Http::timeout($this->timeoutSeconds)->get($url, $query);
            $ms = (int) ((microtime(true) - $start) * 1000);

            if ($response->ok()) {
                Log::info('Meal API success', [
                    'url' => $url,
                    'query' => $query,
                    'status' => $response->status(),
                    'ms' => $ms,
                ]);
                return $response->json() ?? [];
            }

            Log::warning('Meal API non-200 response', [
                'url' => $url,
                'query' => $query,
                'status' => $response->status(),
                'ms' => $ms,
                'body' => Str::limit($response->body(), 500),
            ]);
        } catch (\Throwable $e) {
            $ms = (int) ((microtime(true) - $start) * 1000);
            Log::error('Meal API request failed', [
                'url' => $url,
                'query' => $query,
                'ms' => $ms,
                'error' => $e->getMessage(),
                'trace' => Str::limit($e->getTraceAsString(), 500),
            ]);
            return [];
        }
        return [];
    }

    private function normalizeCountry(?string $country): string
    {
        return Str::lower(preg_replace('/[^a-zA-Z]/', '', (string) $country));
    }

    private function dedupeMeals(array $meals): array
    {
        $seen = [];
        $output = [];
        foreach ($meals as $meal) {
            $id = $meal['idMeal'] ?? null;
            if ($id && !isset($seen[$id])) {
                $seen[$id] = true;
                $output[] = $meal;
            }
        }
        return $output;
    }

    private function shuffle(array $items): array
    {
        $shuffled = $items;
        shuffle($shuffled);
        return $shuffled;
    }
}
