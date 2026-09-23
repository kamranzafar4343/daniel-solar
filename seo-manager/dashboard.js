'use strict';
const $ = id => document.getElementById(id);
let csrf = '', pages = [], current = null, mode = 'edit', dirty = false, hostingReady = false, busy = false;
let requestGeneration = 0;

function notify(message, error = false) {
  $('notice').textContent = message;
  $('notice').classList.toggle('error', error);
  $('notice').hidden = !message;
}
async function api(action, data) {
  const url = new URL('api.php', location.href);
  url.searchParams.set('action', action);
  const opts = {credentials: 'same-origin', cache: 'no-store', headers: {}};
  if (data !== undefined) {
    opts.method = 'POST'; opts.headers = {'Content-Type': 'application/json', 'X-CSRF-Token': csrf}; opts.body = JSON.stringify(data);
  }
  const response = await fetch(url, opts);
  const result = await response.json();
  if (!response.ok) {
    const error = new Error(result.error || 'The request did not finish. Try again.'); error.status = response.status; throw error;
  }
  return result;
}
function setBusy(value) {
  busy = value;
  document.querySelectorAll('button, input, textarea, select').forEach(control => control.disabled = value);
  $('save').disabled = value || !hostingReady;
}
async function task(fn) {
  if (busy) return;
  setBusy(true);
  try { await fn(); } catch (error) {
    notify(error.message, true);
    if (error.status === 401) {
      $('workspace').hidden = true; $('logout').hidden = true; $('login-view').hidden = false;
      $('preview-dialog').close(); $('preview-frame').removeAttribute('src');
      if (dirty) notify('Your session expired. Sign in again to continue with your draft; it is still open in this tab.', true);
    }
  }
  finally { setBusy(false); }
}
function setDirty(value = true) {
  dirty = value;
  $('draft-state').textContent = value ? 'Unsaved draft' : 'No unsaved changes';
  $('save-note').textContent = value ? 'Your changes are not published yet.' : 'Preview before publishing.';
}
function mayLeave() { return !dirty || confirm('You have an unsaved draft. Discard it and continue?'); }
function hosting(data) {
  hostingReady = !!data.ready;
  $('hosting-status').textContent = data.ready ? 'Hosting check passed · File publishing is available on this server.' : 'Publishing is locked until this server passes the hosting write check.';
  $('hosting-results').replaceChildren(...(data.results || []).map(item => {
    const li = document.createElement('li'); li.textContent = `${item.ok ? '✓' : '✕'} ${item.name}`; return li;
  }));
  $('save').disabled = busy || !hostingReady;
}
async function loadPages() {
  const data = await api('pages'); pages = data.pages; hosting(data.hosting); renderPages();
}
function renderPages() {
  const query = $('page-search').value.toLowerCase();
  const matches = pages.filter(page => `${page.path} ${page.title}`.toLowerCase().includes(query));
  $('page-count').textContent = pages.length;
  $('page-list').replaceChildren(...matches.map(page => {
    const button = document.createElement('button'); button.type = 'button'; button.disabled = busy;
    button.classList.toggle('active', current?.path === page.path && mode === 'edit');
    if (current?.path === page.path && mode === 'edit') button.setAttribute('aria-current', 'page');
    const title = document.createElement('strong'); title.textContent = page.title;
    const path = document.createElement('small'); path.textContent = '/' + page.path;
    button.append(title, path); button.addEventListener('click', () => { if (mayLeave()) task(() => openPage(page.path)); }); return button;
  }));
  if (!matches.length) { const p = document.createElement('p'); p.textContent = 'No matching pages.'; $('page-list').append(p); }
}
async function openPage(path) {
  const generation = ++requestGeneration;
  const response = await fetch(`api.php?action=page&path=${encodeURIComponent(path)}`, {cache: 'no-store', credentials: 'same-origin'});
  const data = await response.json();
  if (!response.ok) { const error = new Error(data.error); error.status = response.status; throw error; }
  if (generation !== requestGeneration) return;
  current = data; mode = 'edit'; renderEditor(); notify('');
}
function option(value, label) { const node = document.createElement('option'); node.value = value; node.textContent = label; return node; }
function renderEditor() {
  $('empty').hidden = true; $('editor').hidden = false;
  const isNew = mode === 'new';
  $('new-fields').hidden = !isNew; $('content-section').hidden = isNew; $('link-section').hidden = isNew; $('reload').hidden = isNew; $('live-link').hidden = isNew;
  $('slug').required = isNew; $('heading').required = isNew; $('body-copy').required = isNew;
  $('editor-kicker').textContent = isNew ? 'CREATE A PAGE' : 'PAGE EDITOR';
  $('editor-name').textContent = isNew ? 'A new page for your expertise.' : current.path;
  $('save').textContent = isNew ? 'Create & publish' : 'Save & publish';
  if (!isNew) $('live-link').href = '/' + current.path + '?seo-preview=' + current.version.slice(0, 16);
  for (const key of ['title','description','keywords']) $('meta-' + key).value = current?.meta[key] || '';
  $('add-link').checked = false; $('link-fields').hidden = true; $('link-label').value = ''; toggleLink();
  $('text-search').value = ''; $('chrome-section').open = false;
  $('page-fields').replaceChildren(); $('chrome-fields').replaceChildren();
  if (!isNew) for (const [index, field] of current.fields.entries()) {
    const wrapper = document.createElement('div'); wrapper.className = 'text-field' + (field.multiline ? ' long' : ''); wrapper.dataset.search = field.value.toLowerCase();
    const label = document.createElement('label'); label.htmlFor = field.id; label.textContent = `${field.label} · TEXT ${index + 1}`;
    const input = document.createElement(field.multiline ? 'textarea' : 'input');
    input.id = field.id; input.dataset.textId = field.id; input.value = field.value; input.maxLength = 12000;
    if (field.multiline) input.rows = Math.min(7, Math.max(3, Math.ceil(field.value.length / 110)));
    if (field.numeric) { input.inputMode = 'decimal'; input.pattern = '[0-9]{1,9}(\\.[0-9]{1,4})?'; }
    input.addEventListener('input', () => { wrapper.classList.toggle('changed', input.value !== field.value); wrapper.dataset.search = input.value.toLowerCase(); });
    wrapper.append(label, input); $(field.group === 'chrome' ? 'chrome-fields' : 'page-fields').append(wrapper);
  }
  $('chrome-count').textContent = `(${current?.fields?.filter(f => f.group === 'chrome').length || 0} fields)`;
  $('link-target').replaceChildren(option('', 'Choose a destination…'), ...pages.map(p => option(p.path, p.path)));
  $('link-after').replaceChildren(option('', 'Choose the text to place your link after…'), ...(current?.fields || []).filter(f => f.canLink).map(f => option(f.id, `${f.group === 'chrome' ? 'Header/footer · ' : ''}${f.label}: ${f.value.slice(0, 110)}`)));
  setDirty(false); updateSearch(); filterText(); renderPages(); setBusy(busy);
}
function toggleLink() {
  const enabled = $('add-link').checked && mode === 'edit';
  $('link-fields').hidden = !enabled;
  for (const id of ['link-label','link-target','link-after']) $(id).required = enabled;
}
function filterText() {
  const query = $('text-search').value.toLowerCase().trim(); let count = 0, chromeMatch = false;
  document.querySelectorAll('.text-field').forEach(field => {
    field.hidden = !!query && !field.dataset.search.includes(query);
    if (!field.hidden) { count++; if (field.closest('#chrome-fields')) chromeMatch = true; }
  });
  $('text-count').textContent = `${count} text fields${query ? ' match your search' : ' on this page'}`;
  if (query && chromeMatch) $('chrome-section').open = true;
}
function updateSearch() {
  const title = $('meta-title').value, description = $('meta-description').value;
  $('title-count').textContent = `${[...title].length} / 200 · aim for ~60`;
  $('description-count').textContent = `${[...description].length} / 500 · aim for ~160`;
  $('search-url').textContent = location.host + '/' + (mode === 'new' ? ($('slug').value || 'your-page') + '.html' : current?.path || '');
  $('search-title').textContent = title || 'Your page title'; $('search-description').textContent = description || 'A short description of what visitors will find on this page.';
}
function payload() {
  const meta = Object.fromEntries(['title','description','keywords'].map(key => [key, $('meta-' + key).value]));
  if (mode === 'new') return {mode: 'new', slug: $('slug').value, heading: $('heading').value, body: $('body-copy').value, ...meta};
  const data = {path: current.path, version: current.version, meta, texts: {}};
  document.querySelectorAll('[data-text-id]').forEach(input => { data.texts[input.dataset.textId] = input.value; });
  if ($('add-link').checked) data.link = {target: $('link-target').value, label: $('link-label').value, after: $('link-after').value};
  return data;
}
async function showWorkspace() {
  $('login-view').hidden = true; $('workspace').hidden = false; $('logout').hidden = false; await loadPages();
}
$('login-form').addEventListener('submit', event => { event.preventDefault(); task(async () => {
  const data = await api('login', {email: $('email').value, password: $('password').value}); csrf = data.csrf; $('password').value = ''; notify(''); await showWorkspace();
}); });
$('logout').addEventListener('click', () => { if (mayLeave()) task(async () => {
  const data = await api('logout', {}); csrf = data.csrf; current = null; setDirty(false);
  $('preview-dialog').close(); $('preview-frame').removeAttribute('src');
  $('editor').reset(); $('page-fields').replaceChildren(); $('chrome-fields').replaceChildren(); $('editor').hidden = true; $('empty').hidden = false;
  $('workspace').hidden = true; $('logout').hidden = true; $('login-view').hidden = false; notify('Signed out.');
}); });
$('probe').addEventListener('click', () => task(async () => { const result = await api('probe', {}); hosting(result); $('hosting-details').open = true; notify(result.ready ? 'Hosting check passed. Publishing is enabled for 24 hours; saves still recheck permissions and page versions.' : 'This server cannot safely publish files. Ask the hosting owner to correct the failed permissions, then check again.', !result.ready); }));
$('page-search').addEventListener('input', renderPages);
$('text-search').addEventListener('input', filterText);
$('add-link').addEventListener('change', toggleLink);
$('new-page')?.addEventListener('click', () => { if (!mayLeave()) return; mode = 'new'; current = {meta: {}, fields: []}; $('slug').value = ''; $('heading').value = ''; $('body-copy').value = ''; renderEditor(); setDirty(true); $('slug').focus(); });
$('reload').addEventListener('click', () => { if (mayLeave()) task(() => openPage(current.path)); });
$('editor').addEventListener('input', event => { if (event.target.id === 'text-search') return; setDirty(); updateSearch(); });
$('editor').addEventListener('change', event => { if (event.target.id !== 'text-search') setDirty(); });
$('editor').addEventListener('submit', event => { event.preventDefault(); task(async () => {
  const wasNew = mode === 'new'; const result = await api(wasNew ? 'create' : 'save', payload());
  current = result; mode = 'edit'; renderEditor();
  notify(wasNew ? 'Your page is published. To link to it, open a source page and use Add a page link.' : 'Your changes are published. A recovery copy was saved before publishing.');
  await loadPages();
}); });
$('preview').addEventListener('click', () => { if (!$('editor').reportValidity()) return; task(async () => {
  const result = await api('preview', payload());
  $('preview-dialog').querySelector('small').textContent = 'Loading your page preview…';
  $('preview-frame').src = result.url; $('preview-dialog').showModal();
}); });
$('preview-frame').addEventListener('load', () => {
  $('preview-dialog').querySelector('small').textContent = 'Not published. Links, forms and popups are restricted in this preview.';
});
$('close-preview').addEventListener('click', () => { $('preview-dialog').close(); $('preview-frame').removeAttribute('src'); });
window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
task(async () => { const data = await api('session'); csrf = data.csrf; if (data.user) await showWorkspace(); });
