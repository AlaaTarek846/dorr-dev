<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Every test refreshes the database (drops and re-creates every table), so it must only ever
     * run on the throw-away in-memory SQLite from phpunit.xml. A cached config
     * (php artisan config:cache), a wrong .env, or an app booted from another checkout's vendor
     * folder once pointed a test run at the real development database and wiped it — this stops
     * any such run before the first query.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $default = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$default}.database");
        if ($default !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException("Refusing to run tests on [{$default}: {$database}]: tests must use the in-memory SQLite database from phpunit.xml, or RefreshDatabase would wipe that database.");
        }

        // Booted from a different project folder than these tests (e.g. a shared vendor directory).
        $expected = realpath(dirname(__DIR__));
        if ($expected !== false && realpath($app->basePath()) !== $expected) {
            throw new RuntimeException("Refusing to run tests: the app booted from [{$app->basePath()}], not this project [{$expected}].");
        }

        return $app;
    }
}
