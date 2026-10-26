<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DemoTour;
use App\Models\Expense;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\User;
use App\Support\DemoTourCatalog;
use App\Support\RuntimeSettings;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

class DemoTourService
{
    public function forUser(User $user): DemoTour
    {
        return DemoTour::query()->firstOrCreate(
            ['company_id' => $user->company_id, 'user_id' => $user->id],
            [
                'completed' => [],
                'skipped' => [],
                'baselines' => [],
                'visited' => [],
            ]
        );
    }

    public function choose(User $user, string $mode): array
    {
        $tour = $this->forUser($user);
        if ($mode === 'explore') {
            $tour->mode = 'explore';
            $tour->paused = false;
            $tour->save();

            return $this->payload($user);
        }

        $tour->mode = 'guided';
        $tour->paused = false;
        $tour->completed_at = null;
        if (! $tour->step || DemoTourCatalog::find((string) $tour->step) === null) {
            $tour->step = DemoTourCatalog::steps()[0]['key'];
        }
        $tour->save();
        $this->captureBaseline($tour);

        return $this->payload($user);
    }

    public function act(User $user, string $action, ?string $route = null): array
    {
        $tour = $this->forUser($user);
        if ($route) {
            $this->rememberVisit($tour, $route);
        }

        if ($action === 'seen') {
            return $this->sync($user);
        }
        if ($action === 'pause') {
            $tour->paused = true;
            $tour->save();

            return $this->payload($user);
        }
        if ($action === 'exit') {
            $tour->mode = 'explore';
            $tour->paused = false;
            $tour->save();

            return $this->payload($user);
        }
        if ($action === 'restart') {
            $tour->fill([
                'mode' => 'guided',
                'step' => DemoTourCatalog::steps()[0]['key'],
                'completed' => [],
                'skipped' => [],
                'baselines' => [],
                'visited' => [],
                'paused' => false,
                'completed_at' => null,
            ])->save();
            $this->captureBaseline($tour);

            return $this->payload($user);
        }
        if ($action === 'resume') {
            $tour->mode = 'guided';
            $tour->paused = false;
            $tour->completed_at = null;
            if (! $tour->step) {
                $tour->step = DemoTourCatalog::steps()[0]['key'];
            }
            $tour->save();
            $this->captureBaseline($tour);

            return $this->payload($user);
        }
        if ($action === 'back') {
            $this->move($tour, -1);

            return $this->payload($user);
        }
        if ($action === 'skip') {
            $this->finishStep($tour, 'skipped');

            return $this->payload($user);
        }
        if ($action === 'next') {
            $step = DemoTourCatalog::find((string) $tour->step);
            if ($step && $step['complete'] !== 'acknowledge' && ! $this->satisfied($tour, $step)) {
                throw ValidationException::withMessages(['tour' => $step['pending']]);
            }
            $this->finishStep($tour, 'completed');

            return $this->payload($user);
        }

        return $this->sync($user);
    }

    public function sync(User $user): array
    {
        $tour = $this->forUser($user);
        if ($tour->mode !== 'guided' || $tour->paused || $tour->completed_at) {
            return $this->payload($user);
        }

        $step = DemoTourCatalog::find((string) $tour->step);
        if ($step && $step['complete'] !== 'acknowledge' && $this->satisfied($tour, $step)) {
            $this->finishStep($tour, 'completed');
        }

        return $this->payload($user);
    }

    public function payload(User $user): array
    {
        $tour = $this->forUser($user)->fresh();
        $steps = DemoTourCatalog::steps();
        $index = 0;
        foreach ($steps as $i => $step) {
            if ($step['key'] === $tour->step) {
                $index = $i;
                break;
            }
        }
        $current = $tour->completed_at ? null : ($steps[$index] ?? null);
        $settings = RuntimeSettings::forCompany((int) $user->company_id);
        $email = $settings->poweredByEmail();
        $website = method_exists($settings, 'poweredByWebsite') ? $settings->poweredByWebsite() : '';

        $links = [];
        if ($current && ! empty($current['links'])) {
            foreach ($current['links'] as $link) {
                if (! $user->hasPermission($link['permission']) || ! Route::has($link['route'])) {
                    continue;
                }
                $links[] = ['label' => $link['label'], 'url' => route($link['route'])];
            }
        }

        return [
            'demo' => true,
            'mode' => $tour->mode,
            'paused' => (bool) $tour->paused,
            'finished' => (bool) $tour->completed_at,
            'step' => $current ? [
                'key' => $current['key'],
                'title' => $current['title'],
                'body' => $current['body'],
                'anchor' => $current['anchor'],
                'formAnchor' => $current['form_anchor'] ?? null,
                'formRoute' => $current['form_route'] ?? null,
                'formUrl' => ! empty($current['form_route']) && Route::has($current['form_route']) ? route($current['form_route']) : null,
                'stay' => array_values($current['stay'] ?? []),
                'also' => array_values($current['also'] ?? []),
                'complete' => $current['complete'],
                'url' => route($current['route']),
                'route' => $current['route'],
                'index' => $index + 1,
                'total' => count($steps),
                'links' => $links,
            ] : null,
            'contact' => [
                'email' => $email !== '' ? $email : null,
                'website' => $website !== '' ? $website : null,
            ],
        ];
    }

    protected function finishStep(DemoTour $tour, string $bucket): void
    {
        $key = (string) $tour->step;
        $list = $tour->{$bucket} ?: [];
        if (! in_array($key, $list, true)) {
            $list[] = $key;
        }
        $tour->{$bucket} = array_values($list);
        $this->move($tour, 1);
    }

    protected function move(DemoTour $tour, int $direction): void
    {
        $steps = DemoTourCatalog::steps();
        $keys = array_column($steps, 'key');
        $pos = array_search($tour->step, $keys, true);
        if ($pos === false) {
            $pos = 0;
        }
        $next = $pos + $direction;
        if ($next < 0) {
            $next = 0;
        }
        if ($next >= count($keys)) {
            $tour->completed_at = now();
            $tour->paused = false;
            $tour->save();

            return;
        }
        $tour->step = $keys[$next];
        $tour->completed_at = null;
        $tour->save();
        $this->captureBaseline($tour);
    }

    protected function captureBaseline(DemoTour $tour): void
    {
        $step = DemoTourCatalog::find((string) $tour->step);
        if (! $step || $step['complete'] === 'acknowledge' || $step['complete'] === 'visit') {
            return;
        }
        $baselines = $tour->baselines ?: [];
        if (! array_key_exists($step['key'], $baselines)) {
            $baselines[$step['key']] = $this->metric($step['complete'], (int) $tour->company_id);
            $tour->baselines = $baselines;
            $tour->save();
        }
    }

    protected function satisfied(DemoTour $tour, array $step): bool
    {
        if ($step['complete'] === 'visit') {
            $names = ! empty($step['also']) ? $step['also'] : [$step['route']];
            $visited = $tour->visited ?: [];

            return count(array_intersect($names, $visited)) > 0;
        }
        if ($step['complete'] === 'acknowledge') {
            return false;
        }
        $baseline = (int) (($tour->baselines ?: [])[$step['key']] ?? 0);

        return $this->metric($step['complete'], (int) $tour->company_id) > $baseline;
    }

    protected function metric(string $complete, int $companyId): int
    {
        return match ($complete) {
            'category' => ProductCategory::query()->withoutGlobalScope('company')->where('company_id', $companyId)->count(),
            'product' => Product::query()->withoutGlobalScope('company')->where('company_id', $companyId)->count(),
            'purchase' => PurchaseOrder::query()->withoutGlobalScope('company')->where('company_id', $companyId)->where('status', PurchaseOrder::STATUS_RECEIVED)->count(),
            'customer' => Customer::query()->withoutGlobalScope('company')->where('company_id', $companyId)->where('is_walk_in', false)->count(),
            'sale' => Sale::query()->withoutGlobalScope('company')->where('company_id', $companyId)->where('status', Sale::STATUS_COMPLETED)->where('payment_status', Sale::PAYMENT_PAID)->where('number', 'not like', 'DEMO-%')->count(),
            'expense' => Expense::query()->withoutGlobalScope('company')->where('company_id', $companyId)->where('notes', 'not like', 'Sample demo%')->count(),
            default => 0,
        };
    }

    protected function rememberVisit(DemoTour $tour, string $route): void
    {
        $allowed = [];
        foreach (DemoTourCatalog::steps() as $step) {
            $allowed[] = $step['route'];
            foreach ($step['also'] ?? [] as $name) {
                $allowed[] = $name;
            }
        }
        if (! in_array($route, $allowed, true)) {
            return;
        }
        $visited = $tour->visited ?: [];
        if (! in_array($route, $visited, true)) {
            $visited[] = $route;
            $tour->visited = $visited;
            $tour->save();
        }
    }
}
