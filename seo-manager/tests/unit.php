<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/App.php';
use SolarSeo\{App, Document, Problem};
$assertions = 0;
function check(bool $test, string $label): void { global $assertions; $assertions++; if (!$test) throw new RuntimeException($label); }
function problem(callable $fn, int $status): void { try { $fn(); throw new RuntimeException('Expected failure ' . $status); } catch (Problem $e) { check($e->status === $status, $e->getMessage()); } }
function input(array $page): array { return ['path' => $page['path'], 'version' => $page['version'], 'meta' => $page['meta'], 'texts' => array_column($page['fields'], 'value', 'id')]; }
$site = dirname(__DIR__, 2);
$fixture = sys_get_temp_dir() . '/solar-seo-test-' . bin2hex(random_bytes(8));
mkdir($fixture); mkdir($fixture . '/site'); mkdir($fixture . '/private'); mkdir($fixture . '/site/blog');
file_put_contents($fixture . '/private/config.json', json_encode(['secret' => str_repeat('a', 64), 'origin' => 'https://www.danielsolared.com']));
copy($site . '/index.html', $fixture . '/site/index.html');
copy($site . '/about.html', $fixture . '/site/about.html');
copy($site . '/about.html', $fixture . '/site/blog/nested.html');
$app = new App($fixture . '/site', $fixture . '/private');
try {
    // No-op edits must preserve every byte across the complete real content corpus.
    $real = new App($site, $fixture . '/private');
    foreach ($real->files() as $path => $file) {
        $html = file_get_contents($file); $doc = new Document($html);
        check($doc->render(input($real->page($path))) === $html, 'Roundtrip failed: ' . $path);
        foreach ($doc->fields as $field) check(!str_contains($field['value'], '<script'), 'Script exposed in ' . $path);
    }
    $initial = $app->page('index.html'); $draft = input($initial);
    problem(fn() => $app->save($draft, 'seo@example.test'), 503);
    $probe = $app->probe(); check($probe['ready'], 'Write probe failed');
    check(count(glob($fixture . '/site/.seo*') ?: []) === 0, 'Probe left temporary files');
    $doc = new Document($app->source('index.html'));
    $field = array_values(array_filter($doc->editable(), fn($f) => $f['group'] === 'page' && $f['canLink']))[0];
    $draft['texts'][$field['id']] = 'Solar <script>alert("bad")</script> & safe text';
    $draft['meta']['title'] = 'A safe <title> & new solar title';
    $draft['meta']['description'] = 'Description "quoted" & helpful';
    $draft['meta']['keywords'] = 'solar, design';
    $before = $app->source('index.html');
    $saved = $app->save($draft, 'seo@example.test'); $after = $app->source('index.html');
    check(str_contains($after, '&lt;script&gt;'), 'Text not escaped');
    preg_match_all('~<script\b.*?</script>~is', $before, $scriptsBefore); preg_match_all('~<script\b.*?</script>~is', $after, $scriptsAfter);
    check($scriptsBefore === $scriptsAfter, 'Scripts changed');
    preg_match_all('~\bhref\s*=\s*("[^"]*"|\x27[^\x27]*\x27)~i', $before, $linksBefore); preg_match_all('~\bhref\s*=\s*("[^"]*"|\x27[^\x27]*\x27)~i', $after, $linksAfter);
    check($linksBefore === $linksAfter, 'Existing links changed');
    check($saved['meta']['title'] === $draft['meta']['title'], 'Meta title roundtrip');
    check($saved['meta']['description'] === $draft['meta']['description'], 'Description roundtrip');
    check($saved['meta']['keywords'] === 'solar, design', 'Keywords missing');
    problem(fn() => $app->save($draft, 'seo@example.test'), 409);
    $fresh = input($saved); file_put_contents($fixture . '/site/index.html', $after . "\n<!-- external deployment -->");
    problem(fn() => $app->save($fresh, 'seo@example.test'), 409);
    problem(fn() => $app->page('../private/config.json'), 404);
    $bad = input($app->page('index.html')); $bad['texts']['unknown'] = 'bad'; problem(fn() => $app->save($bad, 'seo@example.test'), 409);
    $new = ['slug' => 'new-solar-guide', 'title' => 'New guide', 'description' => 'A guide', 'keywords' => 'solar', 'heading' => 'Solar <engineering>', 'body' => "First paragraph.\n\nSecond paragraph."];
    [$path, $html] = $app->newDraft($new);
    check(!file_exists($fixture . '/site/' . $path), 'Preview published a page');
    check(str_contains($html, '&lt;engineering&gt;'), 'New heading unsafe');
    $created = $app->create($new, 'seo@example.test');
    check($created['path'] === 'new-solar-guide.html', 'Creation failed');
    problem(fn() => $app->create($new, 'seo@example.test'), 409);
    foreach (['../evil','admin','MixedCase','a/b','test.php','con','lpt9','with space'] as $slug) { $bad = $new; $bad['slug'] = $slug; problem(fn() => $app->create($bad, 'seo@example.test'), 422); }
    $linkInput = input($app->page('index.html'));
    $location = array_values(array_filter($app->page('index.html')['fields'], fn($f) => $f['canLink']))[0]['id'];
    $linkInput['link'] = ['target' => 'new-solar-guide.html', 'label' => 'Read <guide>', 'after' => $location];
    $app->save($linkInput, 'seo@example.test');
    check(str_contains($app->source('index.html'), '<a href="/new-solar-guide.html">Read &lt;guide&gt;</a>'), 'Link was not inserted');
    $bad = input($app->page('index.html')); $bad['link'] = ['target' => 'javascript:alert(1)', 'label' => 'bad', 'after' => $location]; problem(fn() => $app->save($bad, 'seo@example.test'), 422);
    check(count(glob($fixture . '/private/history-*.json')) === 3, 'Missing backups');
    // Quoted >, raw scripts, inline links, hidden text, article headers and HTML entities.
    $html = '<!doctype html><html><head><title>Hi</title><meta name="description" content="before > after"></head><body><header>Menu</header><main><header>Article</header><p data-test="a > b">One <a href="/old.html">two</a> three &amp; four</p><div hidden>Secret</div><script>let x = "<p>do not edit</p>"; if (a < b) foo();</script></main><footer>End</footer></body></html>';
    $doc = new Document($html); $fields = $doc->editable();
    check(array_column($fields, 'value') === ['Menu','Article','One','two','three & four','End'], 'Tokenizer fields incorrect');
    check($fields[0]['group'] === 'chrome' && $fields[1]['group'] === 'page' && !$fields[3]['canLink'], 'Chrome or link classification incorrect');
    check($doc->meta['description'] === 'before > after', 'Quoted angle bracket lost');
    $counterDoc = new Document('<html><head><title>Counter</title></head><body><span data-count="50" data-suffix=" STATES">0</span></body></html>');
    $counter = $counterDoc->editable()[0];
    check($counter['value'] === '50' && !$counter['canLink'], 'Counter must expose its script-backed value');
    $counterInput = ['meta' => $counterDoc->meta, 'texts' => [$counter['id'] => '51']];
    check(str_contains($counterDoc->render($counterInput), 'data-count="51"'), 'Counter script source not updated');
    $counterInput['texts'][$counter['id']] = 'bad'; problem(fn() => $counterDoc->render($counterInput), 422);
    $capability = $app->read('capabilities.json'); $capability['fingerprint'] = 'another-host'; $app->write('capabilities.json', $capability);
    check(!$app->readiness()['ready'], 'A capability result from another host must not enable publishing');
    problem(fn() => $app->save(input($app->page('index.html')), 'seo@example.test'), 503);
    mkdir($fixture . '/site/seo-manager'); mkdir($fixture . '/site/seo-manager/private');
    $embeddedPath = $fixture . '/site/seo-manager/private';
    problem(fn() => new App($fixture . '/site', $embeddedPath), 503);
    file_put_contents($embeddedPath . '/web.config', App::PRIVATE_IIS);
    file_put_contents($embeddedPath . '/config.json.php', App::DATA_GUARD . file_get_contents($fixture . '/private/config.json'));
    $embeddedApp = new App($fixture . '/site', $embeddedPath);
    $embeddedApp->write('test.json', ['protected' => true]);
    check($embeddedApp->read('test.json')['protected'] === true, 'Embedded guarded storage roundtrip');
    check(!file_exists($embeddedPath . '/test.json') && str_starts_with(file_get_contents($embeddedPath . '/test.json.php'), App::DATA_GUARD), 'Embedded data must have a PHP guard');
    file_put_contents($embeddedPath . '/web.config', '<configuration/>');
    problem(fn() => new App($fixture . '/site', $embeddedPath), 503);
    echo 'PASS: ' . $assertions . ' assertions; ' . count($real->files()) . " real HTML pages roundtrip byte-for-byte.\n";
} finally {
    // Remove only the unique test sandbox whose absolute path we created above.
    $resolved = str_replace('\\', '/', realpath($fixture));
    $tempRoot = rtrim(str_replace('\\', '/', realpath(sys_get_temp_dir())), '/') . '/solar-seo-test-';
    if (!str_starts_with($resolved, $tempRoot)) throw new RuntimeException('Unsafe test cleanup path');
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($fixture);
}
