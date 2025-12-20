<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns 401 for unauthenticated agent polling', function () {
    // Ensure that hitting the agent jobs endpoint without a token
    // responds with a 401 (Unauthorized) and not a 500 server error.
    $resp = $this->getJson('/api/v1/agent/jobs');

    $resp->assertStatus(401);
    // Expect JSON body for API auth errors
    $this->assertTrue(
        str_contains($resp->headers->get('content-type'), 'application/json')
    );
});
