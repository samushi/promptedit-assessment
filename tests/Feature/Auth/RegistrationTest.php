<?php

namespace Tests\Feature\Auth;

use App\Providers\FortifyServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('generations.index', absolute: false));
    }

    public function test_sign_ups_are_throttled_per_ip_address()
    {
        // Invalid attempts count too: each one still costs a request.
        foreach (range(1, FortifyServiceProvider::REGISTRATIONS_PER_HOUR) as $ignored) {
            $this->post(route('register.store'), [])->assertRedirect();
        }

        $this->post(route('register.store'), [])->assertTooManyRequests();
    }

    public function test_the_sign_up_limit_does_not_affect_other_auth_pages()
    {
        foreach (range(1, FortifyServiceProvider::REGISTRATIONS_PER_HOUR + 1) as $ignored) {
            $this->post(route('register.store'), []);
        }

        $this->get(route('login'))->assertOk();
        $this->get(route('register'))->assertOk();
    }
}
