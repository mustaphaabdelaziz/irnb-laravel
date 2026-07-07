<?php

namespace App\Support;

class PermissionMap
{
    /** @return array{0:string,1:string}|null [module, action] or null when unguarded. */
    public static function resolve(?string $routeName): ?array
    {
        if ($routeName === null) {
            return null;
        }

        $config = config('permissions');

        if (in_array($routeName, $config['unguarded'], true)) {
            return null;
        }

        if (isset($config['overrides'][$routeName])) {
            return $config['overrides'][$routeName];
        }

        $module = self::matchModule($routeName, $config['modules']);
        if ($module === null) {
            return null; // unmapped => not permission-controlled
        }

        return [$module, self::deriveAction($routeName)];
    }

    private static function matchModule(string $routeName, array $modules): ?string
    {
        $best = null;
        $bestLen = -1;

        foreach ($modules as $prefix => $module) {
            if (($routeName === $prefix || str_starts_with($routeName, $prefix.'.')) && strlen($prefix) > $bestLen) {
                $best = $module;
                $bestLen = strlen($prefix);
            }
        }

        return $best;
    }

    private static function deriveAction(string $routeName): string
    {
        $last = str_contains($routeName, '.') ? substr(strrchr($routeName, '.'), 1) : $routeName;

        return match ($last) {
            'destroy', 'delete' => 'delete',
            'store', 'create' => 'add',
            'index', 'show', 'export', 'template', 'card', 'history',
            'report', 'receipt', 'minutes', 'calendar', 'inventory',
            'preview-serial' => 'view',
            default => 'edit', // update, edit, approve, assign, rent, return, close, counts, participants, …
        };
    }
}
