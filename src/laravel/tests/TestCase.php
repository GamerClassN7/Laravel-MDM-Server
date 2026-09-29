<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // One signing key for the whole test run instead of one in storage/.
        config(['mdm.signing_key' => sys_get_temp_dir().'/laravel-mdm-test-signing.key']);
    }
}
