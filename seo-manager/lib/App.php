<?php
declare(strict_types=1);
namespace SolarSeo;

final class Problem extends \RuntimeException
{
    public function __construct(public int $status, string $message) { parent::__construct($message); }
}
require_once __DIR__ . '/Document.php';

final class App
{
    public const DATA_GUARD = "<?php http_response_code(404); exit; ?>\n";
    public const PRIVATE_IIS = '<configuration><system.webServer><handlers><clear /></handlers><security><requestFiltering><fileExtensions allowUnlisted="false"><clear /></fileExtensions></requestFiltering></security><directoryBrowse enabled="false" /></system.webServer></configuration>';
    public string $root;
    public string $private;
    public array $config;

    public function __construct(?string $root = null, ?string $private = null)
    {
        $this->root = str_replace('\\', '/', realpath($root ?? dirname(__DIR__, 2)) ?: '');
        $this->private = $private ?? (getenv('SOLAR_SEO_PRIVATE') ?: $this->root . '/seo-manager/private');
        $privateReal = realpath($this->private);
        if (!$privateReal) throw new Problem(503, 'SEO setup is required. Upload the protected seo-manager/private folder.');
        $this->private = str_replace('\\', '/', $privateReal);
        self::validatePrivate($this->root, $this->private);
        $this->config = $this->read('config.json');
        if (strlen($this->config['secret'] ?? '') < 64 || !filter_var($this->config['origin'] ?? '', FILTER_VALIDATE_URL)) throw new Problem(503, 'SEO configuration is incomplete.');
    }

    public static function validatePrivate(string $root, string $private): void
    {
        if (!str_starts_with(strtolower($private) . '/', strtolower($root) . '/')) return;
        if (strtolower($private) !== strtolower($root . '/seo-manager/private') || trim((string)@file_get_contents($private . '/web.config')) !== self::PRIVATE_IIS) throw new Problem(503, 'The private folder protection is missing. Upload seo-manager/private/web.config before using this dashboard.');
    }

    private function embedded(): bool { return str_starts_with(strtolower($this->private) . '/', strtolower($this->root) . '/'); }

    public function read(string $name, array $default = []): array
    {
        $file = $this->private . '/' . $name . ($this->embedded() ? '.php' : '');
        if (!is_file($file)) return $default;
        $raw = (string)file_get_contents($file);
        if ($this->embedded()) {
            if (!str_starts_with($raw, self::DATA_GUARD)) throw new Problem(503, 'Private data protection is invalid.');
            $raw = substr($raw, strlen(self::DATA_GUARD));
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new Problem(503, 'Private SEO data could not be read. Ask the site owner to check it.');
        return $data;
    }

    public function write(string $name, array $data): void
    {
        $this->atomic($this->private . '/' . $name . ($this->embedded() ? '.php' : ''), ($this->embedded() ? self::DATA_GUARD : '') . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    public function locked(callable $fn): mixed
    {
        $handle = @fopen($this->private . '/publish.lock', 'c+b');
        if (!$handle || !flock($handle, LOCK_EX)) throw new Problem(503, 'The SEO storage cannot be locked. Check its permissions.');
        try { return $fn(); } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    private function atomic(string $path, string $value): void
    {
        // Same-directory rename prevents visitors seeing a partially written page.
        $temp = dirname($path) . '/.seo-' . bin2hex(random_bytes(16)) . '.tmp';
        $h = @fopen($temp, 'xb');
        if (!$h) throw new Problem(503, 'The server cannot create a publishing file. Check folder permissions.');
        try {
            $offset = 0;
            while ($offset < strlen($value)) {
                $n = fwrite($h, substr($value, $offset));
                if (!$n) throw new Problem(503, 'The server could not finish writing. The original page is unchanged.');
                $offset += $n;
            }
            if (!fflush($h) || (function_exists('fsync') && !fsync($h))) throw new Problem(503, 'The server could not flush the publishing file.');
            fclose($h); $h = null;
            if (!@rename($temp, $path)) throw new Problem(503, 'The server cannot replace this file atomically. The original page is unchanged.');
        } finally {
            if (is_resource($h)) fclose($h);
            if (is_file($temp)) @unlink($temp);
        }
    }

    public function files(): array
    {
        $files = [];
        // Explicit content roots exclude every admin, upload, dependency and private directory.
        foreach (['', 'blog', 'services', 'resources', 'partners', 'press'] as $dir) {
            foreach (glob($this->root . ($dir ? '/' . $dir : '') . '/*.html') ?: [] as $file) {
                if (is_link($file) || !is_file($file)) continue;
                $real = str_replace('\\', '/', realpath($file) ?: '');
                if (!str_starts_with(strtolower($real), strtolower($this->root) . '/')) continue;
                $content = (string)file_get_contents($file);
                if (!preg_match('~<body\b~i', $content) || !preg_match('~</head\s*>~i', $content)) continue;
                $key = ($dir ? $dir . '/' : '') . basename($file);
                $files[$key] = $file;
            }
        }
        uksort($files, static fn($a, $b) => $a === $b ? 0 : ($a === 'index.html' ? -1 : ($b === 'index.html' ? 1 : strcmp($a, $b))));
        return $files;
    }

    public function source(string $path): string
    {
        $files = $this->files();
        if (!isset($files[$path])) throw new Problem(404, 'Choose an existing website page.');
        $html = file_get_contents($files[$path]);
        if ($html === false || strlen($html) > 2000000) throw new Problem(422, 'This page cannot be edited.');
        return $html;
    }

    public function page(string $path): array
    {
        $html = $this->source($path); $doc = new Document($html);
        return ['path' => $path, 'version' => hash('sha256', $html), 'meta' => $doc->meta, 'fields' => $doc->editable(), 'url' => '/' . $path];
    }

    public function listing(): array
    {
        $out = [];
        foreach ($this->files() as $path => $file) {
            preg_match('~<title\b[^>]*>(.*?)</title>~is', (string)file_get_contents($file), $m);
            $out[] = ['path' => $path, 'title' => html_entity_decode($m[1] ?? $path, ENT_QUOTES | ENT_HTML5, 'UTF-8')];
        }
        return $out;
    }

    private function fingerprint(): string { return hash('sha256', $this->root . '|' . PHP_SAPI . '|' . php_uname('n')); }

    public function readiness(): array
    {
        $probe = $this->read('capabilities.json');
        return ['ready' => ($probe['fingerprint'] ?? '') === $this->fingerprint() && ($probe['ok'] ?? false) && ($probe['time'] ?? 0) > time() - 86400, 'checked' => $probe['time'] ?? null, 'results' => $probe['results'] ?? []];
    }

    public function probe(): array
    {
        return $this->locked(function () {
            $dirs = [$this->root => 'Website root', $this->private => 'Private SEO storage'];
            foreach ($this->files() as $key => $file) $dirs[dirname($file)] = dirname($key) === '.' ? 'Website root' : dirname($key);
            $results = []; $ok = true;
            foreach ($dirs as $dir => $label) {
                $name = $dir . '/.seo-probe-' . bin2hex(random_bytes(12)) . '.tmp';
                try {
                    $this->atomic($name, 'first');
                    $this->atomic($name, 'replacement');
                    if (file_get_contents($name) !== 'replacement') throw new \RuntimeException('Probe failed');
                    $linked = $name . '.link';
                    if (!@link($name, $linked)) throw new \RuntimeException('Atomic create unavailable');
                    if (!unlink($linked) || !unlink($name)) throw new \RuntimeException('Probe cleanup failed');
                    $results[] = ['name' => $label, 'ok' => true];
                } catch (\Throwable $e) { $ok = false; $results[] = ['name' => $label, 'ok' => false]; }
                finally { if (is_file($name)) @unlink($name); if (is_file($name . '.link')) @unlink($name . '.link'); }
            }
            foreach ($this->files() as $key => $file) {
                // Open existing pages without truncating or modifying them, testing the worker identity.
                $h = @fopen($file, 'r+b');
                if (!$h) { $ok = false; $results[] = ['name' => $key . ' is read-only', 'ok' => false]; }
                else fclose($h);
            }
            $this->write('capabilities.json', ['fingerprint' => $this->fingerprint(), 'time' => time(), 'ok' => $ok, 'results' => $results]);
            return $this->readiness();
        });
    }

    private function requireReady(): void
    {
        if (!$this->readiness()['ready']) throw new Problem(503, 'Publishing is disabled until this server passes the hosting write check. Run Check hosting in the dashboard.');
    }

    public function draft(array $input): array
    {
        $path = Document::plain($input['path'] ?? null, 180, true);
        $html = $this->source($path);
        if (!is_string($input['version'] ?? null) || !hash_equals(hash('sha256', $html), $input['version'])) throw new Problem(409, 'This page changed since you opened it. Your draft is still here. Copy your changes, then reload the latest page.');
        $link = $input['link'] ?? null;
        if ($link !== null) {
            if (!is_array($link) || !isset($this->files()[$link['target'] ?? ''])) throw new Problem(422, 'Select an existing published page as the link destination.');
        }
        return [$path, $html, (new Document($html))->render($input, $link)];
    }

    public function save(array $input, string $user): array
    {
        return $this->locked(function () use ($input, $user) {
            $this->requireReady();
            [$path, $original, $updated] = $this->draft($input);
            $target = $this->files()[$path];
            if (!is_writable($target)) throw new Problem(503, 'This page is read-only. Ask the host to enable Modify permission.');
            $backup = $this->backup($path, $original, $user, 'edit', hash('sha256', $updated));
            // Recheck after the backup in case another deployment touched the page.
            if (hash('sha256', (string)file_get_contents($target)) !== hash('sha256', $original)) throw new Problem(409, 'The page changed during publishing. Reload it before saving.');
            $this->atomic($target, $updated);
            return $this->page($path) + ['backup' => $backup];
        });
    }

    private function backup(string $path, string $html, string $user, string $action, string $nextHash): string
    {
        $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6));
        $this->write('history-' . $id . '.json', ['path' => $path, 'html' => $html, 'user' => $user, 'action' => $action, 'time' => gmdate('c'), 'before' => hash('sha256', $html), 'after' => $nextHash]);
        return $id;
    }

    public function newDraft(array $input): array
    {
        $slug = Document::plain($input['slug'] ?? null, 80, true);
        if (!preg_match('~^[a-z0-9]+(?:-[a-z0-9]+)*$~D', $slug) || in_array($slug, ['index','admin','seo-manager','login','api','setup','readme','con','prn','aux','nul','com1','lpt1']) || preg_match('~^(com|lpt)[0-9]$~', $slug)) throw new Problem(422, 'Use a unique lowercase slug with letters, numbers and hyphens.');
        $path = $slug . '.html';
        // Also avoid extensionless folders and server-side pages that may share this route.
        foreach ([$slug, $path, $slug . '.aspx', $slug . '.php'] as $candidate) if (file_exists($this->root . '/' . $candidate)) throw new Problem(409, 'That URL is already in use. Choose another slug.');
        $title = Document::plain($input['title'] ?? null, 200, true);
        $description = Document::plain($input['description'] ?? '', 500);
        $keywords = Document::plain($input['keywords'] ?? '', 1000);
        $heading = Document::plain($input['heading'] ?? null, 200, true);
        $body = Document::plain($input['body'] ?? null, 40000, true);
        $template = (string)file_get_contents(__DIR__ . '/../page-template.html');
        // Reuse only the trusted, shipped site chrome, never a user-supplied HTML template.
        $home = $this->source('about.html');
        preg_match('~<header\b[^>]*class="[^"]*\bsite-header\b[^"]*"[^>]*>.*?</header>~is', $home, $header);
        preg_match('~<footer\b[^>]*class="[^"]*\bsite-footer\b[^"]*"[^>]*>.*?</footer>~is', $home, $footer);
        if (!$header || !$footer) throw new Problem(503, 'The About page header/footer has changed. Ask the developer to update the page template.');
        $paragraphs = array_map(static fn($p) => '<p>' . nl2br(Document::escape(trim($p)), false) . '</p>', preg_split('/\R\s*\R/u', $body));
        $html = strtr($template, ['{{title}}' => Document::escape($title), '{{description}}' => Document::escape($description), '{{keywords}}' => Document::escape($keywords), '{{canonical}}' => Document::escape(rtrim($this->config['origin'], '/') . '/' . $path), '{{heading}}' => Document::escape($heading), '{{body}}' => implode("\n", $paragraphs), '{{header}}' => $header[0], '{{footer}}' => $footer[0]]);
        return [$path, $html];
    }

    public function create(array $input, string $user): array
    {
        return $this->locked(function () use ($input, $user) {
            $this->requireReady();
            [$path, $html] = $this->newDraft($input);
            $this->backup($path, '', $user, 'create', hash('sha256', $html));
            // A hard link publishes a fully flushed file with atomic create-if-absent semantics.
            // Probe this separately; never rename over a concurrently created URL.
            $temp = $this->root . '/.seo-new-' . bin2hex(random_bytes(12)) . '.tmp';
            try {
                $this->atomic($temp, $html);
                if (!@link($temp, $this->root . '/' . $path)) throw new Problem(409, 'The URL now exists, or the host does not permit atomic page creation. No existing page was replaced.');
            } finally { if (is_file($temp)) @unlink($temp); }
            return $this->page($path);
        });
    }
}
