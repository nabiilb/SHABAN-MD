<?php

namespace Tests\Unit;

use Tests\TestCase;

/** routes/api-manifest.json is what the web app's contract test checks its calls against. */
class RouteManifestTest extends TestCase
{
    public function test_the_committed_route_manifest_matches_the_router(): void
    {
        $this->assertSame(\App\Support\RouteManifest::build(), file_get_contents(base_path('routes/api-manifest.json')), 'Stale: run php artisan lab:route-manifest');
    }
}
