<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /*
     | The scaffold shipped with RefreshDatabase commented out, which is fine for a
     | project whose home page touches no database. This one's does: the public
     | maintenance middleware reads the settings table before anything renders, so
     | without a migrated database the request died at a missing table and the
     | failure was read as "the known one" for long enough to stop meaning anything.
     */
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
