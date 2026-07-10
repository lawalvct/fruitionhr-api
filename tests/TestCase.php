<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Simulate the SPA: an Origin on a stateful domain makes Sanctum run
        // the session middleware on API routes, matching production behavior.
        $this->withHeader('Origin', 'http://localhost:3000');
    }
}
