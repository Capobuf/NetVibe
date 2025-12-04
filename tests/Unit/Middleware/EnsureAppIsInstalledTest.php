<?php

namespace Tests\Unit\Middleware;

use Mockery;
use Illuminate\Http\Request;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Http\Middleware\EnsureAppIsInstalled;

class EnsureAppIsInstalledTest extends TestCase
{
    use RefreshDatabase;
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_redirects_to_setup_when_no_users()
    {
        Mockery::mock('alias:App\\Models\\User')
            ->shouldReceive('exists')
            ->andReturn(false);

        $middleware = new EnsureAppIsInstalled();

        $request = Request::create('/', 'GET');

        $response = $middleware->handle($request, fn ($req) => new \Illuminate\Http\Response('ok'));

        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);
        $this->assertStringContainsString('/setup', $response->getTargetUrl());
    }

    public function test_allows_excluded_paths_when_no_users()
    {
        Mockery::mock('alias:App\\Models\\User')
            ->shouldReceive('exists')
            ->andReturn(false);

        $middleware = new EnsureAppIsInstalled();

        $request = Request::create('/livewire/test', 'GET');

        $response = $middleware->handle($request, fn ($req) => new \Illuminate\Http\Response('ok'));

        $this->assertEquals('ok', $response->getContent());
    }

    public function test_redirects_to_login_when_users_exist()
    {
        Mockery::mock('alias:App\\Models\\User')
            ->shouldReceive('exists')
            ->andReturn(true);

        $middleware = new EnsureAppIsInstalled();

        $request = Request::create('/', 'GET');

        $response = $middleware->handle($request, fn ($req) => new \Illuminate\Http\Response('ok'));

        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);
        $this->assertStringContainsString('/login', $response->getTargetUrl());
    }
}
