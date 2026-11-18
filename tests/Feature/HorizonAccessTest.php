<?php

use App\Domain\User\Models\User;

// The Horizon dashboard shows job payloads (generation ids, webhook bodies), so
// it is for operators only, in every environment (no "local" bypass).

test('guests cannot open the horizon dashboard', function () {
    $this->get('/horizon')->assertForbidden();
});

test('regular users cannot open the horizon dashboard', function () {
    $this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden();
});

test('operators can open the horizon dashboard when jobs run on redis', function () {
    config(['queue.default' => 'redis']);

    $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/horizon')->assertOk();
});

test('the dashboard is closed when jobs run on a managed queue (Laravel Cloud)', function () {
    config(['queue.default' => 'cloud']);

    $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/horizon')->assertForbidden();
});

test('the local environment does not bypass the gate', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden();
});
