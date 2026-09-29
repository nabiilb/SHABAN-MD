<?php

namespace App\Support;

/** METHOD + path of every API route (Laravel {id} → :id): the contract the web app is tested against. */
final class RouteManifest
{
    public static function build(): string
    {
        $routes = [];
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            $path = preg_replace('/\{(\w+)\??\}/', ':$1', substr($route->uri(), 3));
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = ['method' => $method, 'path' => $path];
            }
        }
        usort($routes, fn ($a, $b) => [$a['path'], $a['method']] <=> [$b['path'], $b['method']]);

        return json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }
}
