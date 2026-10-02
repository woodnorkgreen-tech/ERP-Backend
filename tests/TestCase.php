<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = require __DIR__ . '/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $database = (string) $app['config']->get('database.connections.'.$app['config']->get('database.default').'.database');
        if (! str_ends_with($database, '_test')) {
            throw new \RuntimeException("Refusing to bootstrap tests against non-test database [{$database}].");
        }

        return $app;
    }
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (! str_ends_with($database, '_test')) {
            throw new \RuntimeException("Refusing to run tests against non-test database [{$database}].");
        }
    }
}
