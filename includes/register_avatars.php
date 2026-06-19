<?php
/**
 * includes/register_avatars.php — Starter avatar picker data
 *
 * Resolves the STARTER_AVATARS config constant into renderable picker
 * options: one entry per configured avatar, each carrying a display label
 * and a live profile picture URL.
 *
 * STARTER_AVATARS format (config.php):
 *
 *   define('STARTER_AVATARS', [
 *       'Charlotte' => null,             // label falls back to the
 *                                         // avatar's actual account name
 *       'Brad'      => 'Builder Brad',   // explicit label override
 *   ]);
 *
 * The array key is the avatar's in-world FULL NAME ("Firstname Lastname")
 * — this is both the value stored in pending_registrations.starter_avatar
 * and the value passed as ROBUST's Model parameter (see
 * robust_create_user() in robust_api.php), so it must exactly match an
 * existing grid account's name.
 *
 * The picker image is the configured avatar's OWN PROFILE PICTURE
 * (userprofile.profileImage) — the same portrait shown on profile.php /
 * publicprofile.php — fetched live on every call. There is no separate
 * "starter avatar image" file or config; if the grid operator wants a
 * good picker image, they set a profile picture on that avatar in-world,
 * same as any other resident would. If no profile picture has been set,
 * the standard placeholder silhouette is shown instead (same fallback
 * every other profile image consumer in the portal already uses) —
 * this is a sanity check for the grid operator, not an error condition
 * the portal needs to handle specially.
 *
 * All lookups are SELECT-only against already-approved OpenSim tables
 * (UserAccounts, userprofile) via the existing fetch_account_by_name() /
 * get_user_profile() functions — no new tables, no new write paths.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/profile_data.php';
require_once __DIR__ . '/assets.php';

/**
 * Returns the resolved starter avatar picker options, in the order
 * declared in STARTER_AVATARS.
 *
 * Each option:
 *   'key'       => string  The value submitted/stored as starter_avatar
 *                          (= the array key in STARTER_AVATARS, the
 *                          avatar's full in-world name)
 *   'label'     => string  Display label — the configured override, or
 *                          the avatar's resolved account name if the
 *                          config value is null/empty
 *   'image_url' => string  Profile picture URL (placeholder if unset or
 *                          if the named avatar account doesn't exist)
 *
 * An avatar key that doesn't resolve to any existing UserAccounts row
 * (e.g. a typo in config.php, or the account hasn't been created on
 * this grid yet) is still included — with its configured/raw key as the
 * label and the placeholder image — rather than silently dropped, so a
 * misconfiguration is visible on the registration page itself instead of
 * just quietly not working.
 *
 * Returns an empty array if STARTER_AVATARS is not defined or empty.
 *
 * @return array<int, array{key: string, label: string, image_url: string}>
 */
function get_starter_avatar_options(): array
{
    if (!defined('STARTER_AVATARS') || empty(STARTER_AVATARS)) {
        return [];
    }

    $options = [];

    foreach (STARTER_AVATARS as $key => $label_override) {
        $key = (string)$key;
        [$firstname, $lastname] = split_avatar_full_name($key);

        $resolved_label = trim((string)($label_override ?? ''));
        $image_url      = get_placeholder_url();

        if ($firstname !== '' && $lastname !== '') {
            $account = fetch_account_by_name($firstname, $lastname);

            if ($account !== null) {
                if ($resolved_label === '') {
                    $resolved_label = trim($account['FirstName'] . ' ' . $account['LastName']);
                }

                $profile   = get_user_profile($account['PrincipalID']);
                $image_url = get_profile_image_url($profile['profile_image_uuid']);
            }
        }

        if ($resolved_label === '') {
            $resolved_label = $key;
        }

        $options[] = [
            'key'       => $key,
            'label'     => $resolved_label,
            'image_url' => $image_url,
        ];
    }

    return $options;
}

/**
 * Splits an avatar's "Firstname Lastname" full name into its two parts.
 *
 * OpenSim names are space-separated first/last with no further structure,
 * matching the firstname/lastname fields collected on register.php itself.
 * Only the first space is treated as the separator (so e.g. a hypothetical
 * "Mary Anne Smith" splits as firstname="Mary", lastname="Anne Smith" —
 * consistent with how OpenSim itself only ever has exactly two name parts
 * in UserAccounts, so this ambiguity shouldn't arise in practice for
 * accounts actually created on this grid).
 *
 * @param  string $full_name
 * @return array{0: string, 1: string}  [firstname, lastname]
 */
function split_avatar_full_name(string $full_name): array
{
    $full_name = trim($full_name);
    if ($full_name === '') {
        return ['', ''];
    }

    $parts = preg_split('/\s+/', $full_name, 2);
    if (count($parts) < 2) {
        return ['', ''];
    }

    return [$parts[0], $parts[1]];
}
