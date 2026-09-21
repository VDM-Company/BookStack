<?php

namespace BookStackGoogleAutoLink;

use BookStack\Access\SocialAuthService;
use BookStack\Exceptions\SocialSignInAccountNotUsed;
use BookStack\Users\Models\User;
use BookStack\Users\UserRepo;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Contracts\User as SocialUser;

/**
 * Extends the core social login flow so that a social account whose verified
 * email matches an existing user is linked to that user and logged in, rather
 * than being rejected as unknown.
 *
 * This lets an administrator create an account and have the person sign in with
 * Google straight away, without a password or any prior account linking.
 *
 * Deliberately declares no constructor: the container resolves the parent's
 * promoted dependencies, so this stays a drop-in replacement for the binding
 * registered in the module's functions.php.
 */
class AutoLinkSocialAuthService extends SocialAuthService
{
    /**
     * {@inheritdoc}
     *
     * Core throws SocialSignInAccountNotUsed from exactly one place: the
     * fall-through reached when nobody is logged in and the social account is
     * unknown. Every other outcome returns before that point, so catching it
     * here targets that case precisely without restating the rest of the flow.
     */
    public function handleLoginCallback(string $socialDriver, SocialUser $socialUser)
    {
        try {
            return parent::handleLoginCallback($socialDriver, $socialUser);
        } catch (SocialSignInAccountNotUsed $exception) {
            $user = $this->findUserForAutoLink($socialUser);

            // Re-throw when there's nobody to link to, so that the auto-register
            // fall-back in SocialController still gets its chance to run.
            if ($user === null) {
                throw $exception;
            }

            $user->socialAccounts()->save($this->newSocialAccount($socialDriver, $socialUser));
            $this->loginService->login($user, $socialDriver);

            return redirect()->intended('/');
        }
    }

    /**
     * Find an existing user that the given social account can be automatically
     * linked to, matched via email address. Returns null when no suitable user
     * exists, or when the social account provides no email address, or an
     * unverified one.
     */
    protected function findUserForAutoLink(SocialUser $socialUser): ?User
    {
        $email = trim($socialUser->getEmail() ?? '');
        if ($email === '' || !$this->socialEmailIsVerified($socialUser)) {
            return null;
        }

        $user = app()->make(UserRepo::class)->getByEmail($email);

        // Avoid linking against system users, such as the public "Guest" user,
        // since those are not intended to be logged into.
        if ($user === null || !empty($user->system_name)) {
            return null;
        }

        return $user;
    }

    /**
     * Check that the email address, of the given social user, has been marked as
     * verified by the auth provider.
     *
     * Google always reports this. A provider that reports nothing is taken at its
     * word, since the whole behaviour is opt-out via GOOGLE_AUTO_LINK and a
     * provider is trusted for identity either way.
     */
    protected function socialEmailIsVerified(SocialUser $socialUser): bool
    {
        $rawDetails = ($socialUser instanceof AbstractUser) ? $socialUser->getRaw() : [];
        $verified = $rawDetails['email_verified'] ?? $rawDetails['verified_email'] ?? null;

        if (is_null($verified)) {
            return true;
        }

        return filter_var($verified, FILTER_VALIDATE_BOOLEAN);
    }
}
