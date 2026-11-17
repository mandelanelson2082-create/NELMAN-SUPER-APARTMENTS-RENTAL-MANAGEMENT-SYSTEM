<?php
declare(strict_types=1);

/** Authentication & authorization helpers. Admins and tenants have separate sessions. */

function current_admin(): ?array
{
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    static $cached = null;
    if ($cached && (int) $cached['id'] === (int) $_SESSION['admin_id']) {
        return $cached;
    }
    $cached = db_one('SELECT id, name, username, role FROM admins WHERE id = ?', [$_SESSION['admin_id']], 'i');
    return $cached;
}

function current_tenant(): ?array
{
    if (empty($_SESSION['tenant_id'])) {
        return null;
    }
    static $cached = null;
    if ($cached && (int) $cached['id'] === (int) $_SESSION['tenant_id']) {
        return $cached;
    }
    $cached = get_tenant((int) $_SESSION['tenant_id']);
    return $cached;
}

function require_admin(): array
{
    $admin = current_admin();
    if (!$admin) {
        redirect(base_url('index.php'));
    }
    return $admin;
}

function require_tenant(): array
{
    $tenant = current_tenant();
    if (!$tenant) {
        redirect(base_url('index.php'));
    }
    if (($tenant['status'] ?? '') !== 'ACTIVE') {
        unset($_SESSION['tenant_id']);
        flash_set('error', 'Your account is inactive. Please contact the property manager.');
        redirect(base_url('index.php'));
    }
    if (!empty($tenant['must_change_password'])) {
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!in_array($script, ['change_password.php', 'logout.php'], true)) {
            redirect(base_url('tenant/change_password.php'));
        }
    }
    return $tenant;
}

/** Attempt admin login. Returns the admin row on success, null on failure. */
function login_admin(string $username, string $password): ?array
{
    $admin = db_one('SELECT * FROM admins WHERE username = ?', [$username], 's');
    if ($admin && password_verify($password, $admin['password'])) {
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
        $_SESSION['admin_id'] = (int) $admin['id'];
        unset($_SESSION['tenant_id']);
        return $admin;
    }
    return null;
}

/** Attempt tenant login using phone number or email + password. */
function login_tenant(string $identifier, string $password): ?array
{
    $tenant = db_one(
        "SELECT * FROM tenants
          WHERE (phone = ? OR email = ?)
            AND status = 'ACTIVE'
            AND (email IS NULL OR email = '' OR email_verified_at IS NOT NULL)
          LIMIT 1",
        [$identifier, $identifier],
        'ss'
    );
    if ($tenant && password_verify($password, $tenant['password'])) {
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
        $_SESSION['tenant_id'] = (int) $tenant['id'];
        unset($_SESSION['admin_id']);
        return $tenant;
    }
    return null;
}
