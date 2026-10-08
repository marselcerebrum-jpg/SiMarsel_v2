<?php

namespace App\View\Components;

use App\Models\Account;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Component;
use Illuminate\View\View;

class Sidebar extends Component
{
    /**
     * Menu items grouped by section. An item without a route is a feature
     * that has not been built yet; the view renders it as "coming soon".
     * An item the account is not authorized for is rendered as locked.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    public array $sections;

    public function __construct()
    {
        $this->sections = [
            'Sistem' => [
                $this->link('Manajemen akun', 'gear', 'settings.index', ['viewAny', Account::class]),
            ],
        ];
    }

    /**
     * @param  array{0: string, 1: mixed}|null  $ability
     * @return array<string, mixed>
     */
    private function link(string $label, string $icon, ?string $route = null, ?array $ability = null): array
    {
        $locked = $ability !== null && Gate::denies(...$ability);

        return [
            'label' => $label,
            'icon' => $icon,
            'url' => $route && ! $locked ? route($route) : null,
            'active' => ! $locked && $route !== null && request()->routeIs($route),
            'locked' => $locked,
        ];
    }

    /**
     * Locked children are hidden; a group with no accessible child is shown as locked.
     *
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function group(string $label, string $icon, array $children): array
    {
        $children = array_values(array_filter($children, fn (array $child) => ! $child['locked']));
        $active = collect($children)->contains('active', true);

        return [
            'label' => $label,
            'icon' => $icon,
            'children' => $children,
            'active' => $active,
            'locked' => $children === [],
        ];
    }

    public function render(): View
    {
        return view('components.sidebar');
    }
}
