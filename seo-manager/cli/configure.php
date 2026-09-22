<?php
declare(strict_types=1);
// CLI only. Accept credentials on stdin so passwords never appear in command arguments.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/App.php';
use SolarSeo\{App, Document};
try {
    $input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    $root = str_replace('\\', '/', realpath(dirname(__DIR__, 2)));
    $private = $input['private'] ?? $root . '/seo-manager/private';
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $password = Document::plain($input['password'] ?? '', 72, true);
    $origin = rtrim((string)($input['origin'] ?? ''), '/');
    $url = parse_url($origin);
    $dev = in_array($url['host'] ?? '', ['localhost','127.0.0.1','[::1]']) && ($url['scheme'] ?? '') === 'http';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 14 || strlen($password) > 72) throw new RuntimeException('Use a valid email and a password of at least 14 characters and at most 72 UTF-8 bytes.');
    if (!filter_var($origin, FILTER_VALIDATE_URL) || (!$dev && ($url['scheme'] ?? '') !== 'https') || !empty($url['path']) || isset($url['query']) || isset($url['fragment']) || isset($url['user']) || isset($url['pass'])) throw new RuntimeException('Origin must be the HTTPS site origin with no path, for example https://www.danielsolared.com.');
    if (!is_dir($private) && !mkdir($private, 0700, true)) throw new RuntimeException('Cannot create private storage.');
    $private = str_replace('\\', '/', realpath($private));
    App::validatePrivate($root, $private);
    $embedded = str_starts_with(strtolower($private) . '/', strtolower($root) . '/');
    $file = $private . '/config.json' . ($embedded ? '.php' : '');
    if (!is_file($file)) {
        $config = ['secret' => bin2hex(random_bytes(48)), 'dummy_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), 'origin' => $origin, 'development' => $dev];
        if (file_put_contents($file, ($embedded ? App::DATA_GUARD : '') . json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('Cannot create configuration.');
        @chmod($file, 0600);
    }
    $app = new App($root, $private);
    if ($app->config['origin'] !== $origin) throw new RuntimeException('Existing configuration has a different origin. Update config.json intentionally before resetting a login.');
    $app->locked(function () use ($app, $email, $password) {
        $users = $app->read('users.json');
        $users[$email] = ['hash' => password_hash($password, PASSWORD_DEFAULT), 'active' => true, 'role' => 'seo'];
        $app->write('users.json', $users);
    });
    echo "SEO login created/reset. Private files: $private\nNo database is used. Publishing must pass the dashboard hosting check on the target server.\n";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
