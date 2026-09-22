<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/lib/App.php';
require_once __DIR__ . '/lib/Auth.php';
use SolarSeo\{App, Auth, Problem, Document};

header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Content-Type: application/json; charset=utf-8');
try {
    if (!extension_loaded('mbstring')) throw new Problem(503, 'Enable the PHP mbstring extension in the hosting settings.');
    $app = new App(); $auth = new Auth($app);
    $action = $_GET['action'] ?? 'session';
    $method = $_SERVER['REQUEST_METHOD'];
    $readOnly = in_array($action, ['session', 'pages', 'page', 'preview-document']);
    if ($method !== ($readOnly ? 'GET' : 'POST')) throw new Problem(405, 'This request method is not allowed.');
    $input = [];
    if (!$readOnly) {
        $auth->verify();
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) throw new Problem(415, 'Send JSON data.');
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 2000000) throw new Problem(413, 'This draft is too large.');
        $raw = file_get_contents('php://input', false, null, 0, 2000001);
        if (strlen($raw) > 2000000) throw new Problem(413, 'This draft is too large.');
        $input = json_decode($raw, true);
        if (!is_array($input)) throw new Problem(400, 'The request data is invalid.');
    }
    if (!in_array($action, ['session','login'])) $user = $auth->user();
    switch ($action) {
        case 'session': $result = ['csrf' => $auth->csrf(), 'user' => $auth->user(false), 'hosting' => $auth->user(false) ? $app->readiness() : null]; break;
        case 'login': $result = $auth->login($input); break;
        case 'logout': $auth->logout(); $result = ['ok' => true, 'csrf' => $auth->csrf()]; break;
        case 'pages': $result = ['pages' => $app->listing(), 'hosting' => $app->readiness()]; break;
        case 'page': $result = $app->page(Document::plain($_GET['path'] ?? null, 180, true)); break;
        case 'probe': $result = $app->probe(); break;
        case 'save': $result = $app->save($input, $user['email']); break;
        case 'create': $result = $app->create($input, $user['email']); break;
        case 'preview':
            if (($input['mode'] ?? '') === 'new') [$path, $html] = $app->newDraft($input);
            else [$path, , $html] = $app->draft($input);
            // The UI embeds this only in an opaque-origin sandbox. Base resolves nested assets.
            $base = rtrim($app->config['origin'], '/') . '/' . $path;
            $html = preg_replace('~<head\b[^>]*>~i', '$0<base href="' . Document::escape($base) . '">', $html, 1);
            $id = bin2hex(random_bytes(24));
            $_SESSION['preview'] = ['id' => $id, 'html' => $html, 'until' => time() + 600];
            $result = ['url' => 'api.php?action=preview-document&id=' . $id, 'path' => $path]; break;
        case 'preview-document':
            $preview = $_SESSION['preview'] ?? [];
            if (!is_string($_GET['id'] ?? null) || !hash_equals($preview['id'] ?? '', $_GET['id']) || ($preview['until'] ?? 0) < time()) throw new Problem(404, 'This preview expired. Generate another preview.');
            header('Content-Type: text/html; charset=utf-8');
            header("Content-Security-Policy: sandbox allow-scripts; default-src 'self' https: data:; script-src 'self' https: 'unsafe-inline'; style-src 'self' https: 'unsafe-inline'; font-src 'self' https: data:; connect-src 'none'; form-action 'none'; object-src 'none'; frame-ancestors 'self'");
            echo $preview['html']; exit;
        default: throw new Problem(404, 'Unknown SEO action.');
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Problem $e) {
    http_response_code($e->status); echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('Solar SEO: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'The server could not complete the request. Your draft is still available. Ask the site owner to check the PHP error log.']);
}
