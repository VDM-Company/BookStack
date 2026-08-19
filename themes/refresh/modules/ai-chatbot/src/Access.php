<?php

namespace BookStackAiChat;

use BookStack\Users\Models\User;

/**
 * Decides who may use the assistant. Shared by the widget view (to decide
 * whether to render at all) and the controller (which enforces it).
 */
class Access
{
    public static function allows(?User $user, Config $config): bool
    {
        if (!$config->configured() || $user === null) {
            return false;
        }

        // On a public instance the guest user satisfies the `auth` middleware,
        // so anonymous visitors could otherwise spend API credits.
        if ($user->isGuest() && !$config->allowGuests()) {
            return false;
        }

        $allowed = $config->allowedRoles();

        if ($allowed === []) {
            return true;
        }

        foreach ($user->roles as $role) {
            if (in_array((string) $role->id, $allowed, true)) {
                return true;
            }

            if (in_array((string) $role->display_name, $allowed, true)) {
                return true;
            }
        }

        return false;
    }
}
