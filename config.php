<?php
/**
 * OpenSim Web Portal — Configuration
 *
 * All grid-specific settings live here. Copy this file to config.local.php
 * and fill in your own values. config.local.php is never committed to version control.
 */

// ─── Portal URL ───────────────────────────────────────────────────────────────
//
// The public base URL of this portal, with no trailing slash.
// Used when constructing links in outgoing emails (e.g. email verification).
// Change this to match your grid's portal address.
//
define('PORTAL_BASE_URL', 'https://portal.sub-version.space');


// ─── Grid identity ────────────────────────────────────────────────────────────
define('GRID_NAME',         'Sub-Version Grid');
define('GRID_SUBTITLE',     'Your virtual world, on the web');

// ROBUST_PUBLIC_HOST — the public-facing hostname of the grid's ROBUST
//   server (what's reachable from the internet). This may differ from
//   ROBUST_HOST (used for internal/private connections, often 'localhost').
define('ROBUST_PUBLIC_HOST', 'grid.sub-version.space');

// ROBUST_PUBLIC_PORT — the public-facing ROBUST port (the one viewers like
//   Firestorm connect to for login). This port is always open externally.
//   8002 is the OpenSimulator default but other grids may use a different
//   port. Used for the grid login URI and for fetching map tiles from the
//   grid's HTTP map tile service (see region_image.php).
define('ROBUST_PUBLIC_PORT', 8002);

// ─── Grid public identity (display only) ─────────────────────────────────────
//
// These are shown in the login page footer and are purely cosmetic — they have
// no effect on internal connections (which use ROBUST_HOST/ROBUST_PRIVATE_PORT above).
//
// GRID_DISPLAY_NAME — the public-facing name shown in the footer.
//                     Defaults to GRID_NAME if not set.
//
// GRID_LOGIN_URI    — the login URI shown to visitors so they know what to
//                     type in their viewer. Typically http://yourdomain:8002
//
define('GRID_DISPLAY_NAME', 'Sub-Version Space');
define('GRID_LOGIN_URI',    'http://' . ROBUST_PUBLIC_HOST . ':' . ROBUST_PUBLIC_PORT);

// ─── Login page appearance ────────────────────────────────────────────────────
//
// SHOW_LOGIN_BACKGROUND — display a background image on the login page.
//   true  (default) — a random image from the active theme's bgimages/
//                     folder is chosen on each page load, giving a fresh
//                     look every visit.
//   false           — the login page uses the plain CSS gradient background.
//
// Background images now live inside each theme, at:
//   themes/<theme>/bgimages/
// Drop any .jpg / .jpeg / .png / .webp files in there — no other
// configuration is needed. If a theme has no bgimages/ folder (or it's
// empty), themes/default/bgimages/ is used instead. This applies to ALL
// pages (login, splash, register, public profiles, and the dimmed
// background overlay on logged-in pages) — one consistent set of images
// per theme.
//
// LOGIN_BACKGROUND_DIR (legacy) — no longer used. Background images are now
// sourced from themes/<theme>/bgimages/ as described above. If you were
// using the old splashimages/ folder, move its contents into
// themes/default/bgimages/.
//
define('SHOW_LOGIN_BACKGROUND', true);

// SHOW_LOGIN_GRID_STATS — show a small "Grid Status" panel on the login page
//   with live statistics drawn from the database (member count, online now,
//   region count, etc.).  Set to false to hide the panel entirely.
//
define('SHOW_LOGIN_GRID_STATS', true);

// STATS_SYSTEM_ACCOUNT_COUNT — number of internal/service accounts to subtract
//   from the public Members figure. These are accounts that exist in UserAccounts
//   for grid infrastructure purposes (e.g. NPC owners, scripting service users,
//   the god-mode admin account) but should not appear in the public member count.
//
//   Set to 0 if all accounts in your grid are genuine residents.
//   Other portal operators should adjust this to match their own setup.
//
define('STATS_SYSTEM_ACCOUNT_COUNT', 3);

// SHOW_NEWS_FEED — show a news/announcements panel on the login page alongside
//   the login form. When true the login card expands to a two-pane layout:
//   news feed on the left, login fields on the right, separated by a divider.
//   On mobile the news feed stacks above the login fields.
//   When false (default) the login card is exactly as normal.
//   The news feed content itself is a Stage 2 feature — for now an empty panel
//   is shown as a placeholder.
//
define('SHOW_NEWS_FEED', true);


// ─── ROBUST service ───────────────────────────────────────────────────────────
// ROBUST_PRIVATE_PORT — the internal ROBUST port used for XMLRPC/RemoteAdmin
//   and asset service calls. This port is normally firewalled off from the
//   public internet and only reachable by simulators, ROBUST itself, and
//   this portal application.
define('ROBUST_HOST',         'localhost');
define('ROBUST_PRIVATE_PORT', 8003);
define('ASSET_SERVICE_URL',   'http://' . ROBUST_HOST . ':' . ROBUST_PRIVATE_PORT . '/assets');

// MAP_TILE_SERVICE_URL — base URL for the grid's HTTP map tile service.
//   Tiles are fetched as {MAP_TILE_SERVICE_URL}/map-{zoom}-{X}-{Y}-objects.jpg
//   This is served from ROBUST_PUBLIC_HOST/ROBUST_PUBLIC_PORT — the same
//   always-open port viewers use to log in (see "Grid identity" above).
define('MAP_TILE_SERVICE_URL', 'http://' . ROBUST_PUBLIC_HOST . ':' . ROBUST_PUBLIC_PORT);

// Asset image cache — converted PNGs are stored here after first fetch.
// UUIDs are immutable so cached files never go stale; no expiry needed.
// This path must be writable by the web server user and should NOT be
// publicly accessible (outside document root, or denied in Nginx/Apache).
define('ASSET_CACHE_DIR', sys_get_temp_dir() . '/opensim_asset_cache');

// ─── MariaDB connection ───────────────────────────────────────────────────────
define('DB_HOST',     'localhost');
define('DB_PORT',     3306);
define('DB_NAME',     '');
define('DB_USER',     '');
define('DB_PASS',     '');
define('DB_CHARSET',  'utf8mb4');

// ─── User level tiers ─────────────────────────────────────────────────────────
//
// USERLEVEL_LABELS is the SINGLE SOURCE OF TRUTH for both the Account page
// display AND every access-control gate in the portal. Format:
//
//   integer minimum UserLevel => 'Display label'
//
// A user's tier is whichever label has the highest key that is still <= their
// UserLevel — they hold that name up to (but not including) the next-higher
// key. E.g. with the defaults below, UserLevel 199 is 'Trusted', 200 is
// 'Grid Staff', 249 is still 'Grid Staff', 250 is 'Administrator'.
//
// GATING IS BY NAME, NOT NUMBER. user_level_meets($userlevel, 'Grid Staff')
// and user_level_meets($userlevel, 'Administrator') — defined further down
// in this file, see "DO NOT EDIT BELOW THIS LINE" — are how the portal
// checks access everywhere (admin.php, the REST console, estate management
// overrides, etc.) — they look up the label string in this array and
// compare against ITS key, not a separate constant. This means:
//
//   - You may freely change the NUMBERS these two labels map to, to retune
//     which UserLevel unlocks which tier of portal access.
//   - You may freely ADD extra intermediate labels (e.g. 210 => 'Senior
//     Staff') for cosmetic display on the Account page — they show up as
//     that label in the 210-249 range, but grant no additional access of
//     their own; gating still only ever checks for 'Grid Staff' or
//     'Administrator' by name.
//   - You must NOT rename or remove the 'Grid Staff' or 'Administrator'
//     label strings themselves. If you do, user_level_meets() can no longer
//     find them in this array, fails closed, and NOBODY will be able to
//     reach the Administration panel, the REST console, or the
//     Administrator-only estate overrides — that functionality becomes
//     completely unreachable until the label is restored.
//   - The label at the LOWEST key (0 => 'Resident' by default) has no such
//     restriction — nothing in the portal looks it up by name. It is only
//     used POSITIONALLY, as the implicit "nothing special" floor: account.php
//     hides the UserLevel badge entirely for anyone resolving to whichever
//     label currently sits at the lowest key. Rename it to anything you like.
//
define('USERLEVEL_LABELS', [
      0 => 'Resident',     // floor tier — positional only, safe to rename
     75 => 'Trusted',
    200 => 'Grid Staff',   // relied on by name — see warning above
    250 => 'Administrator', // relied on by name — see warning above
]);

// GRID_GOD_LEVEL — the UserLevel at which OpenSim itself grants in-world
// "God" powers. This is an OPENSIM ENGINE CONCEPT, separate from the portal
// tiers above — it only controls the "Grid God" badge shown alongside the
// tier label on the Account page, and otherwise grants no portal access.
// 200 is OpenSim's standard default; change this only if your simulators
// were compiled/configured with a different in-world god threshold.
define('GRID_GOD_LEVEL', 200);

// ─── Inactivity timeouts ──────────────────────────────────────────────────────
//
// Sessions expire after this many seconds of inactivity (no page load).
// Two tiers: one for regular users, a shorter one for users meeting the
// 'Administrator' tier (see USERLEVEL_LABELS / user_level_meets() in
// helpers.php) because admin sessions carry higher risk if left unattended.
//
// Set either value to 0 to disable that tier's timeout (e.g. during development).
//
define('SESSION_TIMEOUT_SECONDS',       3600);  // 1 hour  — regular users
define('ADMIN_SESSION_TIMEOUT_SECONDS', 1800);  // 30 mins — admin users

// ─── Password policy ─────────────────────────────────────────────────────────
//
// These constants control what passwords are accepted at registration and when
// changing a password. Both the server-side validator (includes/password_policy.php)
// and the client-side rule checklist read from here, so changing a value updates
// both without any further code changes.
//
// PW_MIN_LENGTH     — Minimum number of characters. 10 is a reasonable baseline;
//                     NIST SP 800-63B recommends at least 8 but longer is better.
//
// PW_REQUIRE_UPPER  — At least one uppercase letter (A–Z).
// PW_REQUIRE_LOWER  — At least one lowercase letter (a–z).
// PW_REQUIRE_NUMBER — At least one digit (0–9).
// PW_REQUIRE_SYMBOL — At least one non-alphanumeric character (e.g. !@#$%^&*).
//                     Symbols add entropy but can frustrate users; set to false
//                     if you prefer to rely on length + mixed case + numbers.
//
define('PW_MIN_LENGTH',     10);
define('PW_REQUIRE_UPPER',  true);
define('PW_REQUIRE_LOWER',  true);
define('PW_REQUIRE_NUMBER', true);
define('PW_REQUIRE_SYMBOL', false);

// ─── Feature flags ────────────────────────────────────────────────────────────
//
// FEATURE_REGISTRATION — show the public registration page (register.php) and
// its link on the login page. Submissions go into pending_registrations and
// require admin approval (by a user meeting the 'Grid Staff' tier — see
// USERLEVEL_LABELS) before an OpenSim account is created.
//
// Requires the portal_registration.sql migration to have been run (creates
// pending_registrations and portal_log tables) and the portal DB user to have
// INSERT/UPDATE/DELETE on those two tables.
//
define('FEATURE_REGISTRATION', true);

// ADMIN_NOTIFY_EMAILS — email addresses notified when a new registration is
// submitted. Only used if EMAIL_ENABLED is true. Leave empty to disable
// notification emails even when EMAIL_ENABLED is true (admins will still see
// pending registrations in the Administration panel).
//
define('ADMIN_NOTIFY_EMAILS', []);

// STARTER_AVATARS — starter avatars offered on the registration form.
// Leave empty to hide the avatar-selection step entirely (new accounts are
// created with no starting appearance/inventory).
//
// Format: 'Full Avatar Name' => 'Display label' | null
//
//   - The array KEY must be the EXACT "Firstname Lastname" of an existing
//     account on THIS grid. It is used as-is for two things: stored in
//     pending_registrations.starter_avatar, and passed as ROBUST's Model
//     parameter on account creation (see robust_create_user() in
//     robust_api.php) — OpenSim clones that account's
//     appearance/inventory onto the new account.
//   - The VALUE is the label shown to registrants. Set to null to fall
//     back to the avatar's own account name instead of a custom label.
//   - The picker image is NOT configured here — it is the named avatar's
//     own profile picture (set in-world, same as any resident's profile),
//     fetched live. An avatar with no profile picture set shows the
//     standard placeholder silhouette.
//
// Example:
//   define('STARTER_AVATARS', [
//       'Charlotte Avatar' => 'Cheerful Charlotte',
//       'Brad Avatar'      => null,   // label = "Brad Avatar"
//   ]);
//
define('STARTER_AVATARS', [
  'Sandra Avatar' => 'Sandra',
  'Amanda Avatar' => 'Amanda',
  'Victor Avatar' => 'Victor',
  'Thomas Avatar' => 'Thomas'
]);

// ─── OpenSim database write exceptions ─────────────────────────────────────
//
// The portal's write boundary is SELECT-only on OpenSim-owned tables — all
// mutations to OpenSim data should go via ROBUST/RemoteAdmin APIs. The
// features below are DOCUMENTED, DELIBERATE exceptions to that rule, used
// only where no XMLRPC/RemoteAdmin/console API exists for the action.
//
// ALLOW_OS_DATABASE_WRITES is a MASTER SWITCH that takes precedence over
// every individual exception flag below it. If this is false, ALL of the
// features below are disabled regardless of their own setting — see
// os_write_feature_enabled() in includes/helpers.php, which is what every
// such feature should be gated through. Set this to false to guarantee the
// portal never writes to an OpenSim-owned table, no matter what else below
// is configured.
//
define('ALLOW_OS_DATABASE_WRITES', true);

// ENABLE_PARTNERSHIPS — allow local users to offer and accept partnerships
//   via the portal. Partnership state is stored in userprofile.profilePartner,
//   which is the same field used by the in-world viewer, so the partnership
//   is visible both on the portal and in-world.
//   Requires ALLOW_OS_DATABASE_WRITES = true (writes to userprofile).
//
//   Requirements:
//     - The portal DB user must have UPDATE on userprofile (see portal_notifications.sql)
//     - The portal_notifications table must exist (run portal_notifications.sql)
//   Only works between local grid users. Hypergrid visitors never see the offer button.
//
define('ENABLE_PARTNERSHIPS', true);

// ENABLE_CHANGE_MATURITY — allow estate owners/managers (and Grid Staff —
//   a user meeting the 'Grid Staff' tier, see USERLEVEL_LABELS) to change a
//   region's maturity rating (General/Moderate/Adult) from the region modal
//   on regions.php. Writes directly to regions.access.
//   Requires ALLOW_OS_DATABASE_WRITES = true (writes to regions).
//
//   No XMLRPC/RemoteAdmin/console path exists for this — confirmed via the
//   one-shot REST console (`region set` only supports agent-limit and
//   max-agent-limit; no maturity/access parameter exists on this OpenSim
//   build). Direct UPDATE on regions.access is the only available mechanism.
//
//   Important: this changes the DB value only. The running simulator does
//   not pick up the change until the region restarts (same propagation lag
//   already observed with in-world maturity edits) — the maturity picker
//   modal warns the user of this; it does NOT trigger a restart itself.
//   Restarting remains a separate, deliberate action via the existing
//   "Restart region" button.
//
define('ENABLE_CHANGE_MATURITY', true);

// ENABLE_ESTATE_MANAGER_EDIT — allow estate owners to add/remove estate
//   managers from the "Estate Tools" modal on regions.php. Writes directly
//   to estate_managers (INSERT on add, DELETE on remove).
//   Requires ALLOW_OS_DATABASE_WRITES = true (writes to estate_managers).
//
//   No XMLRPC/RemoteAdmin/console path exists for estate manager assignment
//   on this grid — RemoteAdmin only exposes region-level operations
//   (restart, etc.), and no working `region set estate_manager` console
//   command has been confirmed on this OpenSim build (see RestConsole.md /
//   ConsoleAccess.md). Direct INSERT/DELETE on estate_managers is therefore
//   the only available mechanism, same reasoning as ENABLE_CHANGE_MATURITY
//   above.
//
//   Viewing the estate's owner + manager roster (read-only) is available to
//   any estate owner or manager regardless of this flag — it only gates the
//   add/remove actions themselves, which are owner-only in any case (see
//   user_is_estate_owner() in includes/estates.php — managers can never add
//   or remove managers, including themselves).
//
define('ENABLE_ESTATE_MANAGER_EDIT', true);

// ENABLE_ESTATE_OWNER_TRANSFER — allow users meeting the 'Administrator'
//   tier to change an estate's OWNER from the "Change Owner" tool on the
//   "All Estates" admin page (all_estates.php). Writes directly to
//   estate_settings.EstateOwner (UPDATE).
//   Requires ALLOW_OS_DATABASE_WRITES = true (writes to estate_settings).
//
//   No XMLRPC/RemoteAdmin/console path exists for estate ownership transfer
//   on this grid — same situation as ENABLE_ESTATE_MANAGER_EDIT above, just
//   for EstateOwner instead of estate_managers. Direct UPDATE on
//   estate_settings.EstateOwner is therefore the only available mechanism.
//
//   Deliberately gated to the 'Administrator' tier ONLY — unlike
//   ENABLE_ESTATE_MANAGER_EDIT (which also allows the estate's own owner to
//   act), changing the owner is never available to the estate's current
//   owner or managers acting alone, since the action redefines who that is.
//   See user_can_change_estate_owner() in includes/helpers.php.
//
define('ENABLE_ESTATE_OWNER_TRANSFER', true);

// PARTNER_INWORLD_NOTIFY — when true, partnership events (accept, dissolve)
//   INSERT a row into im_offline so both parties receive an in-world IM.
//   Messages are sent from the grid robot account (GRID_ROBOT_UUID) using a
//   fixed session UUID (GRID_ROBOT_SESSION_UUID) so all portal IMs always land
//   in the same conversation tab in the viewer.
//
define('PARTNER_INWORLD_NOTIFY', true);

// ─── Grid robot account ───────────────────────────────────────────────────────
//
// A dedicated grid account used as the "sender" for portal-generated in-world
// IMs (partnership notifications etc.). Using a real grid account UUID means
// messages arrive in a proper named conversation tab rather than from an
// unknown/system sender.
//
// GRID_ROBOT_UUID         — PrincipalID of the robot account
// GRID_ROBOT_NAME         — Display name shown in the viewer conversation tab
// GRID_ROBOT_SESSION_UUID — Fixed imSessionID used for all portal IMs.
//                           A static UUID means all portal messages always
//                           thread into the same conversation tab, never
//                           creating duplicate tabs. Choose any valid UUID
//                           that you won't use for anything else.
//
define('GRID_ROBOT_UUID',         '6571e388-6218-4574-87db-f9379718315e');
define('GRID_ROBOT_NAME',         'GRID SERVICES');
define('GRID_ROBOT_SESSION_UUID', '99999999-9999-9999-9999-999999999999');

// ─── In-world messaging relay (optional) ───────────────────────────────────────
//
// Portal-generated in-world IMs (partnership notifications etc.) are ALWAYS
// written to the im_offline table — this is reliable, requires no extra setup,
// and guarantees delivery on the recipient's next login regardless of their
// current online status.
//
// Additionally, if an in-world relay object has ever checked in (see
// inworld_relay.lsl / inworld_checkin.php), the portal also sends a
// best-effort "heads up" via that object using llInstantMessage(). This means
// users who are currently online see the message immediately (in Nearby Chat
// / IM history — object IMs don't get a named conversation tab, which is
// normal OpenSim behaviour). The message appears to come from the object
// itself (name it "SYSTEM" or similar).
//
// No config flag is needed to enable this — the presence of a checked-in
// relay object (a row in portal_inworld_relay) is itself the signal. If no
// object has checked in, or the heads-up call fails for any reason, it is
// silently skipped — the im_offline write above has already guaranteed
// delivery.
//
// To deploy the relay object: drop inworld_relay.lsl into a single prim
// somewhere secure and permanent, add a notecard named "access_code"
// containing the same value as INWORLD_RELAY_ACCESS_CODE below, and rez it.
// It checks in with the portal automatically on startup and region restart.
//
// INWORLD_RELAY_ACCESS_CODE — shared secret between the portal and the
//   in-world relay object. The object reads this value from a notecard
//   inside itself and sends it with every check-in and the portal sends it
//   back with every heads-up request, so each side can confirm the other is
//   legitimate.
//
//   Change this from the default before deploying the relay object.
//   Keep it secret — anyone with this code could trigger IMs "from" your
//   object if they discovered its HTTPIN URL.
//
define('INWORLD_RELAY_ACCESS_CODE', '');

// PUBLIC_PROFILES — allow users to opt in to having their profile visible
// without logging in. Two conditions must BOTH be true for a public profile
// to be accessible:
//   1. This constant must be true (grid operator enables the feature).
//   2. The individual user must have checked "Make my profile public" in
//      their Account settings (stored in portal_prefs.public_profile).
//
// Default: false — profiles are always login-gated until the operator
// explicitly enables this feature.
//
define('PUBLIC_PROFILES', true);

// ─── Theme system ─────────────────────────────────────────────────────────────
//
// The active theme is chosen per-device by the user via the Theme & Display
// modal and stored in the portal_prefs cookie.  DEFAULT_THEME is the fallback
// when no cookie is present (new visitors, cleared cookies).
//
// A theme is any folder under /themes/ that contains a CSS file named after
// the folder (e.g. /themes/default/default.css).  Drop a new folder in and it
// appears in the theme picker automatically — no code changes needed.
//
// Optionally, place a <themename>-thumbnail.png (or .jpg / .webp) in the theme
// folder to show a preview image in the picker.
//
define('DEFAULT_THEME', 'default');



// ─── Email ────────────────────────────────────────────────────────────────────
//
// EMAIL_ENABLED controls whether the portal can send email at all.
//
// Set to false if:
//   - Your server is on domestic broadband (most ISPs block port 25 outbound
//     and you cannot set PTR/rDNS records, meaning major providers like Gmail
//     will reject your mail)
//   - You have no SMTP relay available
//   - You simply don't need email notifications
//
// When false: account changes (email address, password) are applied silently
// with no confirmation sent. Less secure, but the only option if mail
// cannot be sent.
//
// When true: email address changes use a confirm-then-set flow — a
// verification link is sent to the new address and the change is only applied
// when clicked. A notification is also sent to the old address.
//
define('EMAIL_ENABLED',      false);
define('EMAIL_FROM_ADDRESS', 'noreply@yourgrid.example.com');
define('EMAIL_FROM_NAME',    GRID_NAME);

// EMAIL_TRANSPORT controls how mail is sent.
//
//   'php'  — uses PHP's built-in mail() function. Simple but relies on
//            the server's local MTA (sendmail/postfix). Works on many
//            shared hosts. Not suitable if your server has no MTA.
//
//   'smtp' — uses SMTP directly via PHPMailer. Required if you are using
//            an external SMTP relay (e.g. your hosting provider's relay,
//            Amazon SES, Mailgun, Brevo, etc.). Fill in the SMTP_* settings
//            below.
//
define('EMAIL_TRANSPORT', 'php');   // 'php' or 'smtp'

// SMTP settings — only used when EMAIL_TRANSPORT = 'smtp'
//
// SMTP_ENCRYPTION:
//   'tls'  — STARTTLS (most common — use with port 587)
//   'ssl'  — Implicit TLS (use with port 465)
//   ''     — No encryption (not recommended; use only on trusted local relays)
//
define('SMTP_HOST',       'smtp.example.com');
define('SMTP_PORT',       587);
define('SMTP_ENCRYPTION', 'tls');   // 'tls', 'ssl', or ''
define('SMTP_USERNAME',   '');
define('SMTP_PASSWORD',   '');

// ─── RemoteAdmin ─────────────────────────────────────────────────────────────
//
// Powers region restart ("My Estates" region modal) and other RemoteAdmin-
// backed actions (OAR/IAR save & load — see includes/remoteadmin.php). All
// three constants must be set and non-empty or these features stay disabled
// with a graceful "RemoteAdmin is not configured on this portal." message.
//
// REQUIRES, in EACH simulator's [RemoteAdmin] section (OpenSim.ini):
//   [RemoteAdmin]
//   enabled = true
//   access_password = <shared password, matching REMOTEADMIN_PASSWORD below>
//   enabled_methods = all   ; or a list including admin_restart
//
// This assumes every simulator is reachable at the same host (typically
// '127.0.0.1' when the portal and simulators run on the same machine) and
// shares the same access_password. See includes/remoteadmin.php for full
// design notes, the per-region port-resolution model, and security notes
// around RemoteAdmin's use of plain HTTP.
//
define('REMOTEADMIN_ENABLED',  true);
define('REMOTEADMIN_HOST',     '127.0.0.1');
define('REMOTEADMIN_PASSWORD', '');

// ─── REST Console ──────────────────────────────────────────────────────────
//
// ENABLE_REST_CONSOLE — provides users meeting the 'Administrator' tier (see
// USERLEVEL_LABELS) with an interactive web console to each region's
// simulator (and ROBUST), via OpenSimulator's REST console interface. See
// RestConsole.md for full design notes.
//
// This gives FULL, UNRESTRICTED console access — including destructive
// commands such as shutdown, kick, and region config changes — to anyone
// meeting the gate. Treat CONSOLE_PASS with the same care as RemoteAdmin's
// access_password.
//
// REQUIRES:
//   - Every region's simulator (and ROBUST, if you want a "Robust" console
//     target) must be started with -console=rest instead of -console=basic.
//     This is a one-time, per-process switch and is mutually exclusive with
//     -console=basic — the screen session for a switched process becomes a
//     passive log-output window only.
//   - ConsoleUser / ConsolePass set in each process's [Network] section
//     (typically via shared defaults, e.g. SubVersion-Defaults.ini), matching
//     CONSOLE_USER / CONSOLE_PASS below.
//   - ConsolePort left at its default of 0 — the REST console then shares
//     the process's existing http_listener_port (regions.serverPort for
//     simulators; ROBUST_PRIVATE_PORT for Robust). No separate port
//     configuration is needed.
//
define('ENABLE_REST_CONSOLE', true);

// CONSOLE_HOST — host/IP shared by ALL simulators' REST consoles. As with
//   RemoteAdmin, this assumes every simulator is reachable at the same host
//   (typically '127.0.0.1' / 'localhost' when the portal and simulators run
//   on the same machine).
//
define('CONSOLE_HOST', 'localhost');

// CONSOLE_ROBUST_HOST — host for the "Robust" console target (the dropdown
//   entry on the Console admin page). Defaults to ROBUST_HOST if left blank.
//   Only relevant if ROBUST is also started with -console=rest.
//
define('CONSOLE_ROBUST_HOST', '');

// CONSOLE_USER / CONSOLE_PASS — credentials matching ConsoleUser/ConsolePass
//   in each process's [Network] section. Shared across all regions and
//   ROBUST. Never sent to the browser — used server-side only.
//
define('CONSOLE_USER', '');
define('CONSOLE_PASS', '');


// ─────────────────────────────────────────────────────────────────────────────
// DO NOT EDIT BELOW THIS LINE
// ─────────────────────────────────────────────────────────────────────────────
//
// Everything below is derived directly from the configuration above — not new
// configuration of its own. USERLEVEL_LABELS (defined earlier in this file)
// is the actual config a grid operator edits; the three functions below are
// just the lookup logic needed to read it correctly (resolving a raw
// UserLevel to a tier name, and checking a UserLevel against a named tier).
//
// They live here, in config.php, rather than in includes/helpers.php,
// deliberately: config.php is required first by every page, before
// includes/auth.php and includes/helpers.php, and includes/auth.php's
// session-timeout logic needs to call user_level_meets() too. Putting these
// functions anywhere that depends on helpers.php would create a require-order
// problem for auth.php. Keeping a single copy here — rather than duplicating
// the lookup logic separately inside auth.php — avoids the two copies ever
// drifting out of sync with each other.

/**
 * Resolve a raw UserLevel to its named tier, per USERLEVEL_LABELS above.
 *
 * USERLEVEL_LABELS is a sparse map of "minimum UserLevel => tier name". A
 * user's tier is whichever label has the HIGHEST key that is still <= their
 * UserLevel — they hold that name up to (but not including) the next-higher
 * key. E.g. with the default map (0=Resident, 75=Trusted, 200=Grid Staff,
 * 250=Administrator), UserLevel 199 resolves to 'Trusted', 200 resolves to
 * 'Grid Staff', 249 still resolves to 'Grid Staff', 250 resolves to
 * 'Administrator'.
 *
 * Sorts the map by key before walking it, so this is robust even if a grid
 * operator's USERLEVEL_LABELS happens to be defined out of ascending order
 * (it shouldn't be, but this costs nothing and removes a footgun).
 *
 * Returns null only for UserLevel below every defined key (e.g. negative
 * UserLevels used by OpenSim system/service accounts — see
 * public_profile_data.php). A normal UserLevel-0 resident resolves to
 * whatever label sits at the lowest key (typically 'Resident'), not null.
 *
 * This is read-only introspection — it grants no access by itself. See
 * user_level_meets() below for the actual access-control gate built on top
 * of it, and the USERLEVEL_LABELS comment above for the warning about
 * renaming 'Grid Staff' / 'Administrator'.
 *
 * @param  int $userlevel  Raw UserLevel value (e.g. from UserAccounts.UserLevel)
 * @return string|null     The resolved tier label, or null if below every
 *                          defined key
 */
function get_user_tier_label(int $userlevel): ?string
{
    $labels = USERLEVEL_LABELS;
    ksort($labels);

    $resolved = null;
    foreach ($labels as $min_level => $label) {
        if ($userlevel >= $min_level) {
            $resolved = $label;
        }
    }
    return $resolved;
}

/**
 * Does this UserLevel meet or exceed the named tier?
 *
 * This is THE access-control gate for the whole portal — every "is this
 * user Grid Staff or above" / "is this user an Administrator" check should
 * call this rather than comparing UserLevel against a number directly.
 * Gating is by NAME, not number: USERLEVEL_LABELS above is the single place
 * a grid operator changes to retune which UserLevel unlocks which tier of
 * access. See that constant's comment for the rule that 'Grid Staff' and
 * 'Administrator' must keep those exact label strings, or every call site
 * below stops matching anyone and that access becomes unreachable.
 *
 * If $label isn't present anywhere in USERLEVEL_LABELS (e.g. it was renamed
 * or removed), this fails CLOSED — returns false for every UserLevel,
 * including very high ones. It does not throw.
 *
 * @param  int    $userlevel  Acting user's raw UserLevel
 * @param  string $label      Tier name to check against, e.g. 'Grid Staff'
 *                              or 'Administrator' — must exactly match a
 *                              value in USERLEVEL_LABELS
 * @return bool
 */
function user_level_meets(int $userlevel, string $label): bool
{
    $required_level = array_search($label, USERLEVEL_LABELS, true);
    if ($required_level === false) {
        return false;
    }
    return $userlevel >= $required_level;
}

/**
 * Is this UserLevel at the lowest-defined tier (the implicit "default,
 * nothing special" rung — UserLevel 0 by OpenSim convention, though the
 * exact number and its label are whatever a grid operator has configured
 * as the lowest key in USERLEVEL_LABELS)?
 *
 * This is POSITIONAL, not name-based — it checks "is this the first rung"
 * rather than checking for a specific label string. Unlike 'Grid Staff' and
 * 'Administrator', the lowest tier's name is NOT relied on anywhere by
 * string — a grid operator may freely rename it (e.g. 'Resident' to
 * 'Citizen') without breaking anything that uses this function.
 *
 * Used by account.php to decide whether to show a UserLevel badge at all —
 * the badge is suppressed exactly when this returns true.
 *
 * @param  int $userlevel  Raw UserLevel value
 * @return bool
 */
function user_is_lowest_tier(int $userlevel): bool
{
    $labels = USERLEVEL_LABELS;
    ksort($labels);
    $lowest_key = array_key_first($labels);
    return get_user_tier_label($userlevel) === $labels[$lowest_key];
}

