<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The test case is set per-file using uses() so that each test file can
| explicitly declare its base TestCase. See individual test files.
|
*/

beforeEach(function (): void {
	Http::preventStrayRequests();
});
