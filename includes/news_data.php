<?php
/**
 * news_data.php — News & Updates data access
 *
 * Provides CRUD-ish helpers for the portal-owned `portal_news` table, used by:
 *   - admin.php          (post / hide / unhide / delete, and the full admin list)
 *   - login.php           (visible posts panel)
 *   - splash.php          (visible posts panel)
 *
 * Permissions are enforced by the callers (admin.php), not here — these
 * functions are plain data access. The one exception is delete vs hide:
 * delete_news_post() does not itself check UserLevel; admin.php must only
 * call it for users meeting the 'Administrator' tier (see USERLEVEL_LABELS
 * / user_level_meets() in config.php).
 *
 * Schema: see portal_news.sql
 *   id, author_uuid, posted_at, body, hidden
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/profile_data.php';

/**
 * Fetch all news posts (visible and hidden), newest first, with author
 * display names resolved.
 *
 * Intended for the Admin "News & Updates" panel.
 *
 * @return array<int, array{
 *     id: int,
 *     author_uuid: string,
 *     author_name: string,
 *     posted_at: string,
 *     body: string,
 *     hidden: bool
 * }>
 */
function get_all_news_posts(): array
{
    try {
        $stmt = get_db()->query(
            "SELECT n.id, n.author_uuid, n.posted_at, n.body, n.hidden,
                    ua.FirstName AS firstname, ua.LastName AS lastname
               FROM portal_news n
          LEFT JOIN UserAccounts ua ON ua.PrincipalID = n.author_uuid
              ORDER BY n.posted_at DESC, n.id DESC"
        );
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('news_data: get_all_news_posts failed: ' . $e->getMessage());
        return [];
    }

    return array_map('format_news_row', $rows);
}

/**
 * Fetch visible (not hidden) news posts, newest first, for public display
 * on the Login and Splash pages.
 *
 * @param  int $limit  Maximum number of posts to return (default 20). Now
 *                     that the news panels on login.php/splash.php scroll
 *                     internally, this is mainly a sanity ceiling rather
 *                     than the effective display limit.
 * @return array<int, array{
 *     id: int,
 *     author_uuid: string,
 *     author_name: string,
 *     posted_at: string,
 *     body: string,
 *     hidden: bool
 * }>
 */
function get_visible_news_posts(int $limit = 20): array
{
    $limit = max(1, min(50, $limit));

    try {
        $stmt = get_db()->prepare(
            "SELECT n.id, n.author_uuid, n.posted_at, n.body, n.hidden,
                    ua.FirstName AS firstname, ua.LastName AS lastname
               FROM portal_news n
          LEFT JOIN UserAccounts ua ON ua.PrincipalID = n.author_uuid
              WHERE n.hidden = 0
              ORDER BY n.posted_at DESC, n.id DESC
              LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('news_data: get_visible_news_posts failed: ' . $e->getMessage());
        return [];
    }

    return array_map('format_news_row', $rows);
}

/**
 * Normalise a raw portal_news/UserAccounts join row into the shape returned
 * by get_all_news_posts() / get_visible_news_posts().
 *
 * @param  array $row
 * @return array{id: int, author_uuid: string, author_name: string, posted_at: string, body: string, hidden: bool}
 */
function format_news_row(array $row): array
{
    $first = trim((string)($row['firstname'] ?? ''));
    $last  = trim((string)($row['lastname'] ?? ''));
    $name  = trim($first . ' ' . $last);

    return [
        'id'          => (int)$row['id'],
        'author_uuid' => (string)$row['author_uuid'],
        'author_name' => $name !== '' ? $name : 'Unknown User',
        'posted_at'   => (string)$row['posted_at'],
        'body'        => (string)$row['body'],
        'hidden'      => (bool)$row['hidden'],
    ];
}

/**
 * Create a new news post.
 *
 * @param  string $author_uuid  PrincipalID of the poster
 * @param  string $body         Plain text post body
 * @return bool                  True on success
 */
function create_news_post(string $author_uuid, string $body): bool
{
    $body = trim($body);
    if ($body === '') {
        return false;
    }

    try {
        $stmt = get_db()->prepare(
            "INSERT INTO portal_news (author_uuid, body, hidden) VALUES (:author, :body, 0)"
        );
        return $stmt->execute([':author' => $author_uuid, ':body' => $body]);
    } catch (Throwable $e) {
        error_log('news_data: create_news_post failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Set the hidden flag on a news post.
 *
 * Available to grid staff (meeting the 'Grid Staff' tier — see
 * USERLEVEL_LABELS in config.php) — caller is responsible for the
 * permission check.
 *
 * @param  int  $id      portal_news.id
 * @param  bool $hidden  true to hide, false to unhide
 * @return bool          True on success
 */
function set_news_post_hidden(int $id, bool $hidden): bool
{
    try {
        $stmt = get_db()->prepare(
            "UPDATE portal_news SET hidden = :hidden WHERE id = :id"
        );
        return $stmt->execute([':hidden' => $hidden ? 1 : 0, ':id' => $id]);
    } catch (Throwable $e) {
        error_log('news_data: set_news_post_hidden failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Permanently delete a news post.
 *
 * Administrator-only (meeting the 'Administrator' tier — see
 * USERLEVEL_LABELS in config.php) — caller is responsible
 * for the permission check. This is intentionally NOT gated here so the
 * restriction is visible and enforced once, in admin.php's POST handler.
 *
 * @param  int $id  portal_news.id
 * @return bool     True on success
 */
function delete_news_post(int $id): bool
{
    try {
        $stmt = get_db()->prepare("DELETE FROM portal_news WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    } catch (Throwable $e) {
        error_log('news_data: delete_news_post failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Fetch a single news post by id (any visibility). Used to re-validate
 * before hide/unhide/delete actions.
 *
 * @param  int $id  portal_news.id
 * @return array|null
 */
function get_news_post(int $id): ?array
{
    try {
        $stmt = get_db()->prepare("SELECT id, author_uuid, posted_at, body, hidden FROM portal_news WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('news_data: get_news_post failed: ' . $e->getMessage());
        return null;
    }
}
