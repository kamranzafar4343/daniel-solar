<?php
declare(strict_types=1);
namespace SolarSeo;

final class Auth
{
    public function __construct(private App $app)
    {
        $secure = strtolower(parse_url($app->config['origin'], PHP_URL_SCHEME) ?? '') === 'https';
        if (!$secure && !(($app->config['development'] ?? false) && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1']))) throw new Problem(503, 'HTTPS is required for the SEO login.');
        $sessions = $app->private . '/sessions';
        if (!is_dir($sessions) && !@mkdir($sessions, 0700) && !is_dir($sessions)) throw new Problem(503, 'Hosting permissions prevent sign-in: PHP cannot create seo-manager/private/sessions. In Plesk, give the website PHP worker Modify permission on seo-manager/private, including its files and subfolders.');
        if (!is_writable($sessions)) throw new Problem(503, 'Hosting permissions prevent sign-in: seo-manager/private/sessions is read-only for PHP. In Plesk, apply Modify permission to seo-manager/private and its files and subfolders.');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', '1800');
        session_save_path($sessions);
        session_name('DANIEL_SOLAR_SEO');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/seo-manager/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
        if (!@session_start()) throw new Problem(503, 'The server cannot write the SEO login session. Check PHP worker Modify permission on seo-manager/private/sessions and available disk space.');
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public function csrf(): string { return $_SESSION['csrf']; }

    public function verify(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (!is_string($token) || !hash_equals($this->csrf(), $token) || ($origin !== '' && !hash_equals(rtrim($this->app->config['origin'], '/'), $origin))) throw new Problem(403, 'Your session could not be verified. Reload the dashboard and try again.');
    }

    public function user(bool $required = true): ?array
    {
        $identity = $_SESSION['identity'] ?? null;
        $users = $this->app->read('users.json');
        $user = $identity ? ($users[$identity['email']] ?? null) : null;
        if (!$user || ($user['role'] ?? '') !== 'seo' || !($user['active'] ?? false) || !hash_equals(hash('sha256', $user['hash']), $identity['stamp'] ?? '') || ($identity['last'] ?? 0) < time() - 1800 || ($identity['started'] ?? 0) < time() - 28800) {
            unset($_SESSION['identity']);
            if ($required) throw new Problem(401, 'Please sign in to the SEO manager.');
            return null;
        }
        $_SESSION['identity']['last'] = time();
        return ['email' => $identity['email'], 'role' => 'seo'];
    }

    public function login(array $input): array
    {
        $email = strtolower(trim(Document::plain($input['email'] ?? '', 254)));
        $password = Document::plain($input['password'] ?? '', 72);
        if (strlen($password) > 72) throw new Problem(422, 'Passwords must be at most 72 UTF-8 bytes.');
        // Only REMOTE_ADDR is trusted; forwarded headers are attacker-controlled without proxy config.
        $ip = hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown', $this->app->config['secret']);
        $account = hash_hmac('sha256', $email, $this->app->config['secret']);
        $ok = $this->app->locked(function () use ($email, $password, $ip, $account) {
            $attempts = array_filter($this->app->read('attempts.json'), static fn($a) => $a['until'] > time());
            foreach (['ip-' . $ip => 40, 'user-' . $account => 8] as $key => $limit) if (($attempts[$key]['count'] ?? 0) >= $limit) throw new Problem(429, 'Too many sign-in attempts. Try again in 15 minutes.');
            $users = $this->app->read('users.json'); $user = $users[$email] ?? null;
            $hash = $user['hash'] ?? $this->app->config['dummy_hash'] ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $valid = password_verify($password, $hash) && $user && ($user['active'] ?? false) && ($user['role'] ?? '') === 'seo';
            if ($valid) unset($attempts['user-' . $account]);
            else foreach (['ip-' . $ip, 'user-' . $account] as $key) $attempts[$key] = ['count' => ($attempts[$key]['count'] ?? 0) + 1, 'until' => $attempts[$key]['until'] ?? time() + 900];
            $this->app->write('attempts.json', $attempts);
            return $valid ? $user : null;
        });
        if (!$ok) throw new Problem(401, 'The email or password is incorrect.');
        session_regenerate_id(true);
        $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'identity' => ['email' => $email, 'stamp' => hash('sha256', $ok['hash']), 'started' => time(), 'last' => time()]];
        return ['user' => $this->user(), 'csrf' => $this->csrf()];
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}
