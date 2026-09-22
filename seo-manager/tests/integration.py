"""Real PHP + Chromium flow tests in an isolated copy; never edits project pages."""
from pathlib import Path
import hashlib
import json
import os
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.request
from playwright.sync_api import sync_playwright, expect

SOURCE = Path(__file__).resolve().parents[2]
ARTIFACTS = Path(__file__).resolve().parent / 'artifacts'
ARTIFACTS.mkdir(exist_ok=True)
PHP = shutil.which('php')
checks = []

def check(value, label):
    assert value, label
    checks.append(label)

with tempfile.TemporaryDirectory(prefix='solar-seo-http-') as tmp:
    root = Path(tmp) / 'site'
    private = root / 'seo-manager' / 'private'
    root.mkdir()
    for ext in ('*.html', '*.css', '*.js', '*.svg', '*.png'):
        for source in SOURCE.glob(ext):
            shutil.copy2(source, root / source.name)
    for directory in ('blog', 'services', 'resources', 'partners', 'press', 'seo-manager'):
        shutil.copytree(SOURCE / directory, root / directory, ignore=shutil.ignore_patterns('artifacts', '__pycache__', '*.json.php', 'sessions', 'publish.lock'))
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    origin = f'http://127.0.0.1:{port}'
    email = 'seo@example.test'
    password = 'Temporary-test-password!9283'
    setup = {'email': email, 'password': password, 'origin': origin, 'private': str(private)}
    result = subprocess.run([PHP, str(root / 'seo-manager/cli/configure.php')], input=json.dumps(setup), text=True, capture_output=True)
    check(result.returncode == 0, 'CLI creates private SEO login')
    env = dict(os.environ, SOLAR_SEO_PRIVATE=str(private))
    log = open(ARTIFACTS / 'php-server.log', 'w', encoding='utf-8')
    server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', str(root), str(root / 'seo-manager/tests/router.php')], env=env, stdout=log, stderr=log, creationflags=subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0)
    try:
        for _ in range(60):
            try:
                urllib.request.urlopen(origin + '/seo-manager/', timeout=1)
                break
            except Exception:
                time.sleep(.15)
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True, channel=os.environ.get('SEO_TEST_BROWSER', 'chrome'))
            context = browser.new_context(viewport={'width': 1440, 'height': 1000})
            page = context.new_page()
            errors = []
            page.on('pageerror', lambda e: errors.append(str(e)))
            page.goto(origin + '/seo-manager/')
            page.get_by_role('button', name='Sign in', exact=False).wait_for()
            page.screenshot(path=str(ARTIFACTS / 'login-desktop.png'), full_page=True)
            session = context.request.get(origin + '/seo-manager/api.php?action=session').json()
            csrf = session['csrf']
            def post(action, data, token=None, extra=None):
                headers = {'X-CSRF-Token': token if token is not None else csrf, 'Origin': origin}
                headers.update(extra or {})
                return context.request.post(origin + '/seo-manager/api.php?action=' + action, data=data, headers=headers)
            check(context.request.get(origin + '/seo-manager/api.php?action=pages').status == 401, 'Unauthenticated page access blocked')
            check(post('login', {'email': email, 'password': password}, token='invalid').status == 403, 'Invalid CSRF rejected')
            check(post('login', {'email': email, 'password': password}, extra={'Origin': 'https://attacker.test'}).status == 403, 'Cross-origin login rejected')
            page.locator('#email').fill(email)
            page.locator('#password').fill('wrong-password')
            page.locator('#login-form button').click()
            page.get_by_text('The email or password is incorrect.', exact=True).wait_for()
            check(page.locator('#login-view').is_visible(), 'Invalid login stays on login screen')
            page.locator('#password').fill(password)
            page.locator('#login-form button').click()
            page.locator('#workspace').wait_for(state='visible')
            page.locator('#page-list button').nth(100).wait_for(state='attached')
            session = context.request.get(origin + '/seo-manager/api.php?action=session').json()
            csrf = session['csrf']
            cookies = context.cookies()
            cookie = next(c for c in cookies if c['name'] == 'DANIEL_SOLAR_SEO')
            check(cookie['httpOnly'] and cookie['sameSite'] == 'Strict' and cookie['path'] == '/seo-manager/', 'Dedicated restricted session cookie')
            check(page.locator('#save').is_disabled(), 'Publishing locked before server probe')
            page.locator('#probe').click()
            page.get_by_text('Hosting check passed. Publishing is enabled', exact=False).wait_for()
            page.locator('#page-search').fill('about.html')
            page.locator('#page-list button').first.click()
            page.locator('#editor-name').filter(has_text='about.html').wait_for()
            check(not page.locator('#chrome-section').get_attribute('open'), 'Header and footer collapsed by default')
            check(page.locator('#page-fields input').count() > 0 and page.locator('#page-fields textarea').count() > 0, 'Compact and paragraph fields rendered')
            page.locator('#text-search').fill('family-owned')
            check(page.locator('#page-fields .text-field:visible').count() > 0, 'Text search finds paragraphs')
            page.locator('#text-search').fill('All rights reserved')
            check(page.locator('#chrome-section').get_attribute('open') is not None, 'Search exposes matching footer fields')
            page.locator('#text-search').fill('')
            page.locator('#chrome-section').evaluate('(e) => e.open = false')
            first = page.locator('#page-fields [data-text-id]').first
            old = first.input_value()
            first.fill('Engineering expertise you can trust')
            page.locator('#meta-title').fill('Daniel Solar | Updated About Page')
            page.locator('#meta-description').fill('Updated description for solar engineering expertise.')
            page.locator('#meta-keywords').fill('solar engineering, design, permits')
            original = (root / 'about.html').read_bytes()
            page.locator('#preview').click()
            page.locator('#preview-dialog').wait_for(state='visible')
            frame = page.frame_locator('#preview-frame')
            frame.get_by_text('Engineering expertise you can trust', exact=True).wait_for()
            expect(frame.locator('body')).to_have_css('background-color', 'rgb(250, 247, 240)')
            preview_frame = next(f for f in page.frames if 'preview-document' in f.url)
            preview_frame.wait_for_load_state('load')
            preview_frame.evaluate('() => document.fonts.ready')
            check((root / 'about.html').read_bytes() == original, 'Draft preview never writes public page')
            check(frame.locator('link[rel=stylesheet]').count() > 0, 'Preview includes original styles')
            page.screenshot(path=str(ARTIFACTS / 'preview-desktop.png'), full_page=False)
            frame.locator('h1').screenshot(path=str(ARTIFACTS / 'preview-heading.png'))
            page.locator('#close-preview').click()
            page.locator('#save').click()
            page.get_by_text('Your changes are published.', exact=False).wait_for()
            check('Engineering expertise you can trust' in (root / 'about.html').read_text(encoding='utf-8'), 'Visible text published')
            saved = context.request.get(origin + '/seo-manager/api.php?action=page&path=about.html').json()
            check(saved['meta']['title'] == 'Daniel Solar | Updated About Page' and saved['meta']['keywords'] == 'solar engineering, design, permits', 'Metadata saved')
            public = context.request.get(origin + '/about.html?v=' + saved['version']).text()
            check('Engineering expertise you can trust' in public, 'Published public preview returns new content')
            # Conflict introduced outside the dashboard, with unsaved user work retained.
            page.locator('#meta-title').fill('My unsaved draft title')
            with (root / 'about.html').open('a', encoding='utf-8') as handle:
                handle.write('\n<!-- external editor -->')
            page.locator('#save').click()
            page.get_by_text('This page changed since you opened it.', exact=False).wait_for()
            check(page.locator('#meta-title').input_value() == 'My unsaved draft title', 'Conflict keeps unsaved draft')
            check('My unsaved draft title' not in (root / 'about.html').read_text(encoding='utf-8'), 'Conflict prevents overwrite')
            page.once('dialog', lambda dialog: dialog.accept())
            page.locator('#reload').click()
            expect(page.locator('#meta-title')).to_have_value('Daniel Solar | Updated About Page')
            page.locator('#new-page').click()
            page.locator('#slug').fill('solar-design-test-guide')
            page.locator('#heading').fill('A practical solar design guide')
            page.locator('#body-copy').fill('First useful paragraph.\n\nSecond useful paragraph with <safe text>.')
            page.locator('#meta-title').fill('Solar Design Test Guide')
            page.locator('#meta-description').fill('A practical introduction to solar design.')
            page.locator('#preview').click()
            page.locator('#preview-dialog').wait_for(state='visible')
            frame.get_by_role('heading', name='A practical solar design guide').wait_for()
            check(not (root / 'solar-design-test-guide.html').exists(), 'New-page preview does not publish')
            page.locator('#close-preview').click()
            page.locator('#save').click()
            page.get_by_text('Your page is published.', exact=False).wait_for()
            check((root / 'solar-design-test-guide.html').is_file(), 'Create page UI publishes URL')
            new_html = (root / 'solar-design-test-guide.html').read_text(encoding='utf-8')
            check('&lt;safe text&gt;' in new_html and '<header class="site-header">' in new_html, 'New page escapes content and reuses site theme')
            # Add a link from an existing page to the newly published page.
            page.locator('#page-search').fill('about.html')
            page.locator('#page-list button').first.click()
            page.locator('#editor-name').filter(has_text='about.html').wait_for()
            page.locator('#add-link').check()
            page.locator('#link-target').select_option('solar-design-test-guide.html')
            page.locator('#link-label').fill('Read our solar design guide')
            page.locator('#link-after').select_option(index=1)
            page.locator('#preview').click()
            page.locator('#preview-dialog').wait_for(state='visible')
            frame.get_by_role('link', name='Read our solar design guide').wait_for()
            page.locator('#close-preview').click()
            page.locator('#save').click()
            page.get_by_text('Your changes are published.', exact=False).wait_for()
            check('<a href="/solar-design-test-guide.html">Read our solar design guide</a>' in (root / 'about.html').read_text(encoding='utf-8'), 'Link flow preserves and appends links')
            page.evaluate('window.scrollTo(0, 0)')
            page.screenshot(path=str(ARTIFACTS / 'editor-desktop.png'), full_page=False)
            # Password reset revokes a session; reauthentication must preserve the unsaved tab.
            page.locator('#meta-title').fill('Draft retained after signing in again')
            reset = subprocess.run([PHP, str(root / 'seo-manager/cli/configure.php')], input=json.dumps(setup), text=True, capture_output=True)
            check(reset.returncode == 0, 'Owner can reset the SEO password')
            page.locator('#save').click()
            page.locator('#login-view').wait_for(state='visible')
            page.locator('#password').fill(password)
            page.locator('#login-form button').click()
            page.locator('#workspace').wait_for(state='visible')
            check(page.locator('#meta-title').input_value() == 'Draft retained after signing in again', 'Reauthentication preserves draft')
            csrf = context.request.get(origin + '/seo-manager/api.php?action=session').json()['csrf']
            page.locator('#save').click()
            page.get_by_text('Your changes are published.', exact=False).wait_for()
            # Malformed input, method restrictions and private route denial.
            check(context.request.get(origin + '/seo-manager/api.php?action=save').status == 405, 'GET cannot save')
            check(context.request.get(origin + '/seo-manager/api.php?action=page&path=../web.config').status == 404, 'Path traversal rejected')
            check(context.request.get(origin + '/seo-manager/lib/App.php').status == 404, 'Private application routes denied by development router')
            for protected in ('config.json.php', 'users.json.php', 'publish.lock', 'sessions/'):
                check(context.request.get(origin + '/seo-manager/private/' + protected).status == 404, 'Protected storage cannot be fetched: ' + protected)
            check(post('create', {'slug': '../escape'}).status == 422, 'Unsafe slug rejected over HTTP')
            # Small-screen usability and logout.
            page.set_viewport_size({'width': 390, 'height': 844})
            page.locator('#editor-name').scroll_into_view_if_needed()
            page.screenshot(path=str(ARTIFACTS / 'editor-mobile.png'), full_page=False)
            check(page.evaluate('document.documentElement.scrollWidth <= innerWidth'), 'Mobile dashboard has no horizontal overflow')
            page.locator('#logout').click()
            page.locator('#login-view').wait_for(state='visible')
            check(context.request.get(origin + '/seo-manager/api.php?action=pages').status == 401, 'Logout revokes authenticated access')
            session = context.request.get(origin + '/seo-manager/api.php?action=session').json()
            csrf = session['csrf']
            responses = [post('login', {'email': 'rate@example.test', 'password': 'bad'}).status for _ in range(9)]
            check(responses[-1] == 429, 'Repeated login failures are rate limited')
            check(not errors, 'No dashboard JavaScript exceptions: ' + str(errors))
            browser.close()
        report = '\n'.join('PASS ' + label for label in checks) + f'\n{len(checks)} integration checks passed.\n'
        (ARTIFACTS / 'integration-results.txt').write_text(report, encoding='utf-8')
        print(report)
    finally:
        server.terminate()
        server.wait(timeout=10)
        log.close()
