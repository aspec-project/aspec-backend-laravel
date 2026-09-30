<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // Com SQLite em memória o RefreshDatabase migra uma só vez por processo; o seed dos lookups tem de estar na base para valer em toda a suite.
    protected bool $seed = true;
}
