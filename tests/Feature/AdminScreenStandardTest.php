<?php

namespace Tests\Feature;

use Database\Seeders\Admin\AdminPermissionSeeder;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The admin screen standard (docs/screens/README.md), enforced for every new screen and every new module:
 *
 * 1. the route has a permission (meta.permission) that AdminPermissionSeeder declares;
 * 2. the screen is on its module's map (resources/js/modules/admin/guide/services/<module>.js);
 * 3. the screen has its guide (guide/screens/<module>/<route without "admin.">.js) in Arabic and English;
 * 4. a CRUD screen (a modal of its own that saves) is built like countries: per-action permissions
 *    (useCatalogPermissions) and language tabs (CatalogTranslationTabs) - or its guide states
 *    tech.noTranslatableText: '<why>';
 * 5. every enabled module has a map.
 *
 * Screens and modules that existed before the standard are listed in tests/Fixtures/admin-screens/baseline.json.
 * That list only shrinks: bring a screen up to the standard and its line must go (the test says so).
 */
class AdminScreenStandardTest extends TestCase
{
    private const ROUTES = 'resources/js/modules/admin/routes.js';

    private const VIEWS = 'resources/js/modules/admin/themes/theme-1/views/';

    private const GUIDE = 'resources/js/modules/admin/guide/';

    private const BASELINE = 'tests/Fixtures/admin-screens/baseline.json';

    /** Shell pages that are not screens of a module. */
    private const NOT_SCREENS = ['admin.login', 'admin.dashboard', 'admin.guide', 'admin.profile'];

    /**
     * @return array<string, array{component: ?string, meta: string}>
     */
    private function routes(): array
    {
        $source = file_get_contents(base_path(self::ROUTES));
        $chunks = preg_split("/(?=name: 'admin\.)/", $source);
        $routes = [];

        foreach (array_slice($chunks, 1) as $chunk) {
            if (! preg_match("/^name: '(admin\.[^']+)'/", $chunk, $name)) {
                continue;
            }

            preg_match("/component: page\('([^']+)'\)/", $chunk, $component);
            preg_match('/meta: \{([^}]*)\}/', $chunk, $meta);

            $routes[$name[1]] = ['component' => $component[1] ?? null, 'meta' => $meta[1] ?? ''];
        }

        return array_diff_key($routes, array_flip(self::NOT_SCREENS));
    }

    /**
     * @return array{screens: list<string>, modules: list<string>}
     */
    private function baseline(): array
    {
        return json_decode(file_get_contents(base_path(self::BASELINE)), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, string> route => module key of its map file
     */
    private function mappedScreens(): array
    {
        $mapped = [];

        foreach (glob(base_path(self::GUIDE.'services/*.js')) as $file) {
            preg_match_all("/route: '(admin\.[^']+)'/", file_get_contents($file), $m);

            foreach ($m[1] as $route) {
                $mapped[$route] = basename($file, '.js');
            }
        }

        return $mapped;
    }

    /**
     * @return list<string>
     */
    private function declaredPermissions(): array
    {
        $groups = (new ReflectionMethod(AdminPermissionSeeder::class, 'permissionGroups'))
            ->invoke(new AdminPermissionSeeder);
        $names = [];

        foreach ($groups as $group => $definition) {
            foreach ($definition['actions'] as $action) {
                $names[] = "{$group}.{$action}";
            }
        }

        return $names;
    }

    /**
     * Problems of one screen against the standard, empty when it meets it.
     *
     * @return list<string>
     */
    private function problems(string $route, array $info, array $mapped, array $permissions): array
    {
        $problems = [];

        if (! preg_match("/permission: '([^']+)'/", $info['meta'], $permission)) {
            $problems[] = 'no meta.permission on the route';
        } elseif (! in_array($permission[1], $permissions, true)) {
            $problems[] = "permission {$permission[1]} is not declared in AdminPermissionSeeder";
        }

        if (! isset($mapped[$route])) {
            $problems[] = 'not on any module map (guide/services/<module>.js)';
        } else {
            $file = base_path(self::GUIDE.'screens/'.$mapped[$route].'/'.substr($route, strlen('admin.')).'.js');

            if (! is_file($file)) {
                $problems[] = 'no guide file '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
            } else {
                $guide = file_get_contents($file);

                foreach (["route: '{$route}'", "\n    ar: {", "\n    en: {"] as $needle) {
                    if (! str_contains($guide, $needle)) {
                        $problems[] = 'guide file has no '.trim($needle);
                    }
                }
            }
        }

        if ($info['component'] !== null) {
            $folder = base_path(self::VIEWS.dirname($info['component']));
            $files = glob($folder.'/*.vue') ?: [];
            $code = implode("\n", array_map('file_get_contents', $files));
            // A CRUD screen = a modal of its own that saves (post/put/patch) a record.
            $isCrud = array_filter(glob($folder.'/Modal*.vue') ?: [], fn ($modal) => preg_match('/adminAxios\.(post|put|patch)\(/', file_get_contents($modal)));

            if ($isCrud !== []) {
                if (! str_contains($code, 'useCatalogPermissions')) {
                    $problems[] = 'CRUD screen without useCatalogPermissions (per-action buttons, like countries)';
                }

                // Every CRUD gets language tabs for its names, unless its guide says why it has no text to translate.
                $guide = isset($mapped[$route]) ? @file_get_contents(base_path(self::GUIDE.'screens/'.$mapped[$route].'/'.substr($route, 6).'.js')) : false;
                $declaredNoText = $guide !== false && preg_match("/noTranslatableText: '[^']{10,}'/", $guide);

                if (! str_contains($code, 'CatalogTranslationTabs') && ! $declaredNoText) {
                    $problems[] = "CRUD screen without CatalogTranslationTabs - add language tabs, or set tech.noTranslatableText: '<why>' in its guide";
                }
            }
        }
        return $problems;
    }

    public function test_every_screen_outside_the_baseline_meets_the_standard(): void
    {
        $baseline = $this->baseline()['screens'];
        $mapped = $this->mappedScreens();
        $permissions = $this->declaredPermissions();
        $failures = [];

        foreach ($this->routes() as $route => $info) {
            if (in_array($route, $baseline, true)) {
                continue;
            }

            foreach ($this->problems($route, $info, $mapped, $permissions) as $problem) {
                $failures[] = "{$route}: {$problem}";
            }
        }

        $this->assertSame([], $failures, "Admin screen standard (docs/screens/README.md):\n".implode("\n", $failures));
    }

    public function test_the_baseline_only_shrinks(): void
    {
        $routes = $this->routes();
        $mapped = $this->mappedScreens();
        $permissions = $this->declaredPermissions();
        $stale = [];

        foreach ($this->baseline()['screens'] as $route) {
            if (! isset($routes[$route])) {
                $stale[] = "{$route}: no longer an admin route - remove it from ".self::BASELINE;
            } elseif ($this->problems($route, $routes[$route], $mapped, $permissions) === []) {
                $stale[] = "{$route}: meets the standard now - remove it from ".self::BASELINE;
            }
        }

        $this->assertSame([], $stale, implode("\n", $stale));
    }

    /**
     * Nothing may be added to the baseline - a new screen or module meets the standard from day one. These ceilings are
     * the baseline's size when the standard started (2026-11-10); lower them as lines are removed, never raise them.
     */
    public function test_nothing_is_added_to_the_baseline(): void
    {
        $baseline = $this->baseline();

        $this->assertLessThanOrEqual(120, count($baseline['screens']), 'Screens were added to '.self::BASELINE.' - build the new screen to the standard instead.');
        $this->assertLessThanOrEqual(14, count($baseline['modules']), 'Modules were added to '.self::BASELINE.' - give the new module its map instead.');
    }

    public function test_every_mapped_screen_is_a_real_route(): void
    {
        $routes = $this->routes();
        $ghosts = array_keys(array_diff_key($this->mappedScreens(), $routes));

        $this->assertSame([], $ghosts, 'On a map but not in '.self::ROUTES.': '.implode(', ', $ghosts));
    }

    public function test_every_enabled_module_has_a_map(): void
    {
        $statuses = json_decode(file_get_contents(base_path('modules_statuses.json')), true);
        $maps = [];

        foreach (glob(base_path(self::GUIDE.'services/*.js')) as $file) {
            if (preg_match("/module: '([A-Za-z]+)'/", file_get_contents($file), $m)) {
                $maps[] = $m[1];
            }
        }

        $missing = array_values(array_filter(
            array_keys(array_filter($statuses)),
            fn ($module) => ! in_array($module, $maps, true) && ! in_array($module, $this->baseline()['modules'], true),
        ));

        $this->assertSame([], $missing, 'Modules without a map (guide/services/<module>.js with module: \'<Name>\'): '.implode(', ', $missing));
    }
}
