# Google Auto-Link

Logs a user straight in when their Google account's verified email matches an existing
BookStack user, linking the two on first use.

Stock BookStack rejects a social account it has never seen — *"This Google account is not
linked to any users. Please attach it in your profile settings."* — which a new user cannot
act on, because attaching requires already being logged in. That leaves an administrator
handing out passwords purely so people can reach the page where they connect Google.

With this module, the administrator creates the account with the right email address and the
person signs in with Google immediately.

## Configuration

| Variable           | Default | Effect                                              |
| ------------------ | ------- | --------------------------------------------------- |
| `GOOGLE_AUTO_LINK` | `true`  | Set to `false` to restore stock BookStack behaviour. |

No other setup is needed. Google itself must still be configured as normal
(`GOOGLE_APP_ID` / `GOOGLE_APP_SECRET`).

## What it will and won't link

A link is only made when **all** of these hold:

- Nobody is currently logged in, and the social account is not already known to BookStack.
- The provider reports the email address as **verified** (`email_verified` / `verified_email`).
  Google always sends this. A provider that reports nothing is taken at its word.
- A user with exactly that email address exists.
- That user is not a system account — the public "Guest" user can never be linked.

When no user matches, the module re-throws and the normal flow continues, including the
`auto_register` fall-back if that is switched on.

## How it hooks in

No core BookStack file is modified.

`SocialController` type-hints the concrete `BookStack\Access\SocialAuthService`, and core
binds it nowhere, so it is resolved from the container on each request. The module binds a
subclass over it during `ThemeEvents::APP_BOOT`.

`AutoLinkSocialAuthService` overrides one method, `handleLoginCallback()`. It calls the parent
and catches `SocialSignInAccountNotUsed`, which core throws from exactly one place: the
fall-through reached when nobody is logged in and the social account is unknown. Every other
outcome returns before that point, so the catch targets that single case without restating any
of the surrounding logic.

**Upstream coupling.** That is the trade for keeping core clean. A patch to core would break
loudly, as a merge conflict. This breaks *silently* if a future BookStack release changes
`handleLoginCallback()`'s signature, or stops throwing `SocialSignInAccountNotUsed` from that
branch. The test suite below is the safety net — run it after upgrading BookStack.

## Security note

Auto-linking treats a provider-verified email address as proof that the person controls that
address, and therefore as sufficient to sign in as the matching account. That is the same
assumption behind a password-reset email, and it is why the verification flag is checked
rather than trusted blindly.

The practical consequence: anyone who can obtain a Google account at a BookStack user's email
address inherits that account. For a Workspace domain you control, that is exactly the intent.
Do not enable it against a provider where users can freely claim arbitrary addresses.

## Tests

Needs the `mysql_testing` database that BookStack's own suite uses.

```bash
./tests/run.sh
```

or directly:

```bash
./vendor/bin/phpunit -c themes/refresh/modules/google-auto-link/tests/phpunit.xml
```

The module's config sets `APP_THEME=refresh` so the module actually loads; BookStack's root
`phpunit.xml` sets `APP_THEME=none`, which is why these cases cannot live in `tests/`. Core's
own suite is therefore unaffected by this module and must stay green:

```bash
./vendor/bin/phpunit tests/Auth tests/User
```

### Running core's social tests against this module

`tests/Auth/SocialAuthTest.php` can be pointed at the module's config to check the subclass
stays transparent:

```bash
./vendor/bin/phpunit -c themes/refresh/modules/google-auto-link/tests/phpunit.xml tests/Auth/SocialAuthTest.php
```

Expect **3 of 8 to fail**, and only for this reason: `test_social_login`,
`test_social_autoregister` and `test_social_auto_email_confirm` pin `getEmail()` to an exact
Mockery call count, and auto-link reads the email one extra time. The behaviour those tests
describe is unchanged — registration and auto-registration still happen, because the module
re-throws when no user matches.

Treat any *other* failure there as a real regression.
