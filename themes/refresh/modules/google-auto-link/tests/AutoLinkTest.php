<?php

namespace BookStackGoogleAutoLink\Tests;

use BookStack\Access\LoginService;
use BookStack\Access\SocialAuthService;
use BookStack\Access\SocialDriverManager;
use BookStack\Activity\ActivityType;
use BookStack\Users\Models\Role;
use BookStack\Users\Models\User;
use BookStackGoogleAutoLink\AutoLinkSocialAuthService;
use BookStackGoogleAutoLink\Config;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class AutoLinkTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SERVER['GOOGLE_AUTO_LINK'] = 'true';
        Config::flush();

        parent::tearDown();
    }

    public function test_module_binds_itself_over_the_core_service()
    {
        // Guards the whole module: if APP_BOOT wiring or the container binding
        // stops working, every other case here would silently test core.
        $this->assertInstanceOf(AutoLinkSocialAuthService::class, app(SocialAuthService::class));
    }

    public function test_auto_links_to_existing_user_with_matching_email_and_logs_in()
    {
        $editor = $this->users->editor();
        $this->mockSocialiteForLogin($this->socialUser('auto-link-123', $editor->email));

        $this->get('/login/service/google');
        $resp = $this->get('/login/service/google/callback');
        $resp->assertRedirect('/');

        $this->assertDatabaseHas('social_accounts', [
            'user_id'   => $editor->id,
            'driver'    => 'google',
            'driver_id' => 'auto-link-123',
        ]);
        $this->assertEquals($editor->id, auth()->user()->id);
        $this->assertActivityExists(ActivityType::AUTH_LOGIN, null, 'google; (' . $editor->id . ') ' . $editor->name);
    }

    public function test_second_login_reuses_the_existing_link()
    {
        $editor = $this->users->editor();
        $this->mockSocialiteForLogin($this->socialUser('auto-link-123', $editor->email));

        $this->get('/login/service/google');
        $this->get('/login/service/google/callback');
        auth()->logout();

        $this->get('/login/service/google');
        $resp = $this->get('/login/service/google/callback');
        $resp->assertRedirect('/');

        $this->assertEquals($editor->id, auth()->user()->id);
        $this->assertEquals(1, $editor->socialAccounts()->where('driver_id', '=', 'auto-link-123')->count());
    }

    public function test_unverified_social_email_is_refused()
    {
        $editor = $this->users->editor();
        $this->mockSocialiteForLogin($this->socialUser('auto-link-123', $editor->email, false));

        $this->get('/login/service/google');
        $resp = $this->followingRedirects()->get('/login/service/google/callback');
        $resp->assertSee(trans('errors.social_account_not_used', ['socialAccount' => 'Google']));

        $this->assertDatabaseMissing('social_accounts', ['driver_id' => 'auto-link-123']);
        $this->assertFalse(auth()->check());
    }

    public function test_system_users_are_refused()
    {
        $guest = $this->users->guest();
        $this->mockSocialiteForLogin($this->socialUser('auto-link-123', $guest->email));

        $this->get('/login/service/google');
        $resp = $this->followingRedirects()->get('/login/service/google/callback');
        $resp->assertSee(trans('errors.social_account_not_used', ['socialAccount' => 'Google']));

        $this->assertDatabaseMissing('social_accounts', ['driver_id' => 'auto-link-123']);
        $this->assertFalse(auth()->check());
    }

    public function test_admin_created_user_can_log_in_without_ever_setting_a_password()
    {
        $editorRole = Role::getRole('editor');
        $this->asAdmin()->post('/settings/users/create', [
            'name'        => 'Invited User',
            'email'       => 'invited-user@example.com',
            'send_invite' => 'true',
            'roles'       => [$editorRole->id],
        ])->assertRedirect('/settings/users');

        $newUser = User::query()->where('email', '=', 'invited-user@example.com')->firstOrFail();
        auth()->logout();

        $this->mockSocialiteForLogin($this->socialUser('new-user-123', $newUser->email));

        $this->get('/login/service/google');
        $resp = $this->get('/login/service/google/callback');
        $resp->assertRedirect('/');

        $this->assertEquals($newUser->id, auth()->user()->id);
        $this->assertDatabaseHas('social_accounts', [
            'user_id'   => $newUser->id,
            'driver'    => 'google',
            'driver_id' => 'new-user-123',
        ]);
    }

    public function test_core_behaviour_is_unchanged_without_the_binding()
    {
        // What a GOOGLE_AUTO_LINK=false install gets: the stock service, which
        // rejects a social account it has never seen. Built explicitly, since
        // asking the container for it would hand back the module's subclass.
        $this->app->bind(SocialAuthService::class, fn ($app) => new SocialAuthService(
            $app->make(Factory::class),
            $app->make(LoginService::class),
            $app->make(SocialDriverManager::class),
        ));

        $editor = $this->users->editor();
        $this->mockSocialiteForLogin($this->socialUser('auto-link-123', $editor->email));

        $this->get('/login/service/google');
        $resp = $this->followingRedirects()->get('/login/service/google/callback');
        $resp->assertSee(trans('errors.social_account_not_used', ['socialAccount' => 'Google']));

        $this->assertDatabaseMissing('social_accounts', ['driver_id' => 'auto-link-123']);
        $this->assertFalse(auth()->check());
    }

    public function test_env_flag_controls_whether_the_module_enables_itself()
    {
        $_SERVER['GOOGLE_AUTO_LINK'] = 'false';
        Config::flush();
        $this->assertFalse(Config::instance()->enabled());

        $_SERVER['GOOGLE_AUTO_LINK'] = 'true';
        Config::flush();
        $this->assertTrue(Config::instance()->enabled());
    }

    /**
     * Build a real socialite user, so that raw provider details (such as the
     * email verification status) are available as they would be in real usage.
     */
    protected function socialUser(string $id, string $email, bool $emailVerified = true): SocialiteUser
    {
        $user = new SocialiteUser();
        $user->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => $emailVerified])
            ->map(['id' => $id, 'name' => 'Social User', 'email' => $email, 'avatar' => null]);

        return $user;
    }

    /**
     * Mock out socialite so that a google login attempt returns the given social user.
     */
    protected function mockSocialiteForLogin(SocialiteUser $socialUser): void
    {
        $mockSocialite = $this->mock(Factory::class);
        $mockSocialDriver = Mockery::mock(Provider::class);

        $mockSocialite->shouldReceive('driver')->with('google')->andReturn($mockSocialDriver);
        $mockSocialDriver->shouldReceive('redirect')->andReturn(redirect('/'));
        $mockSocialDriver->shouldReceive('user')->andReturn($socialUser);
    }
}
