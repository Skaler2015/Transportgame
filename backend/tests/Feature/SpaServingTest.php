<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SpaServingTest extends TestCase
{
    /** The web fallback serves the compiled SPA shell for app routes. */
    public function test_app_routes_return_the_spa_shell(): void
    {
        if (! File::exists(public_path('index.html'))) {
            $this->markTestSkipped('SPA not built (run scripts/build.sh).');
        }

        foreach (['/', '/dashboard', '/fleet', '/contracts'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('Transoria Online', false);
        }
    }

    /** API routes are not swallowed by the SPA fallback. */
    public function test_api_routes_are_not_shadowed_by_the_spa(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'ok');
    }
}
