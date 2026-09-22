# Deploy to the existing danielsolared.com folder

The owner's requested layout is now supported. **No folder is needed in the hosting Home directory.** This replaces the earlier sibling-folder instructions.

```text
Home directory/
  danielsolared.com/                 existing domain document root
    index.html                      existing, unchanged
    web.config                      updated root server configuration
    seo-manager/                    updated dashboard folder
      index.php
      api.php
      dashboard.css
      dashboard.js
      page-template.html
      web.config                    blocks public access to private and internal folders
      lib/
        App.php
        Auth.php
        Document.php
      private/                      inside this domain, NOT another website
        web.config                  denies all HTTP handlers/file extensions
        .htaccess                   extra protection for Apache only
        config.json.php             protected production settings
        users.json.php              protected password hashes; existing login retained
```

## What to upload now

1. Back up the live root `web.config`. Upload/merge the updated project root `web.config` into `danielsolared.com`, alongside `index.html`. Preserve any additional hosting-specific settings in the live file.
2. Replace the deployed `seo-manager` application files with the updated local `seo-manager` folder, **including its `private` subfolder and BOTH nested `web.config` files**. The production account has already been migrated locally; its password is unchanged.
3. Exclude `tests`, test artifacts, `cli`, and documentation. No existing HTML page, `styles.css`, `script.js` or ASP.NET file needs replacing for this installation. Do not replace live HTML with stale local copies.
4. Do not upload either of the older sibling folders `daniel-solar-seo-private` or `daniel-solar-seo-local-private`. They are no longer required on the server. The latter remains a local development configuration only.
5. If a custom `SOLAR_SEO_PRIVATE` PHP environment variable was previously configured, remove it to use the new default `seo-manager/private` location.

On subsequent application updates, **preserve the live contents of `private`**: accounts, sessions, backups and capability records are runtime data. Deploy source/protection rules without overwriting runtime data with an older local copy. For a deliberate account update, start from the current live user file.

## Hosting settings and initial check

- Enable PHP **8.1+** and the `mbstring`, `json` and `session` extensions in GoDaddy Windows Plesk. This dashboard runs as PHP alongside the existing static/ASP.NET site. No database, Composer or Node service is needed.
- Use HTTPS with the exact configured origin, `https://www.danielsolared.com`. Redirect alternate hostnames to that origin.
- Allow the PHP worker to read/write/modify `seo-manager/private` and the editable HTML content files/directories. Atomic rename replacement, hard-link creation and deletion must be supported. The server check tests the actual worker identity with disposable files and does not change existing page content.
- Leave `open_basedir` configured to include the website document root. No outside-domain folder is required for the default storage location.
- Keep IIS request filtering and the supplied protection settings enabled. If IIS reports error 500.19, have the host resolve the named locked/duplicate configuration setting. Do not fix it by removing the private-folder protections.

Open `https://www.danielsolared.com/seo-manager/`, sign in as `seo@danielsolared.com` with the password supplied in the conversation, and click **Check hosting**. Publishing stays disabled until this actual server passes. Capability results are bound to the machine, site root and PHP execution mode and expire after 24 hours. A local test cannot enable production publishing.

Verify these public addresses return **403 or 404**, never file contents or a download:

```text
https://www.danielsolared.com/seo-manager/private/config.json.php
https://www.danielsolared.com/seo-manager/private/users.json.php
https://www.danielsolared.com/seo-manager/private/
```

The parent IIS configuration hides the `private` path. The private directory also removes all HTTP handlers and denies file extensions. Each account/configuration/backup data file has an executable PHP guard that returns 404 and exits if accidentally requested through a functioning PHP handler. The application refuses embedded storage if its required private `web.config` is missing or changed. These are layered protections; **the live IIS access checks are still required**. Do not rename guarded files to plain `.json` or remove their PHP guard. Do not map this directory to another public virtual directory or configure it for static PHP downloads.

Live IIS permissions and protection rules have not been verified from this local workspace. If GoDaddy denies safe file operations, ask the host to fix those capabilities. Under the owner's no-database constraint there is no alternative publishing backend enabled.

Official GoDaddy instructions: [PHP version settings](https://www.godaddy.com/en-ca/help/change-my-php-version-in-windows-hosting-20309?locale=en), [Windows directory permissions](https://se.godaddy.com/help/set-directory-permissions-in-my-windows-hosting-account-16135?lc=en-US).

## Caching

The root `web.config` replaces the previous 30-day static cache with revalidation and disables IIS output/kernel caching. Static assets also revalidate under this simple policy. SEO APIs/previews send `Cache-Control: no-store, private, max-age=0`.

In Cloudflare, bypass edge caching for `/seo-manager/*`, `/`, and `.html` URLs; remove conflicting Cache Everything rules, respect origin/browser cache headers, and purge old cached HTML once after installation. Other assets may remain edge-cacheable. Never cache authenticated dashboard/API responses.

The published-page link includes a content-version query parameter. Browsers already holding the old 30-day HTML response may need a hard refresh until that older cache expires: neither new headers nor an edge purge can retroactively erase a visitor's existing browser cache.

## Accounts and recovery

The production-configured login files are now in this project's `seo-manager/private`. No plaintext password is stored there. To create/reset an account locally, run:

```powershell
.\seo-manager\cli\setup.ps1 -Origin 'https://www.danielsolared.com'
```

The helper prompts for an email and hidden password (14+ characters, maximum 72 UTF-8 bytes). Default output is now the protected in-domain folder. Do not upload CLI tools. Deploy `users.json.php` deliberately when changing accounts. Password changes invalidate existing sessions. To deactivate an account, the owner can set its `active` flag to false while preserving the file's PHP guard. Accounts only have the `seo` role and do not grant access to another admin area.

Private backups use `history-*.json.php`. After the fixed PHP guard, their JSON contains the original HTML, page path, editor, timestamp and before/after hashes. A backup records a publish attempt before the final replacement; compare its `after` hash when investigating a failed publication. Restore the chosen HTML only after checking for newer work. A creation backup has empty previous HTML: remove the new page intentionally rather than restoring an empty document. History is not automatically deleted; include this folder in secure backups and monitor storage usage.

## Editor behavior

- Complete HTML pages in the root, `blog`, `services`, `resources`, `partners`, and `press` are editable. Verification fragments, redirect-only ASP.NET files, scripts and admin areas are excluded.
- Compact/paragraph fields, search, collapsible page-specific header/footer fields, metadata, draft previews, page creation and internal links are available. Untouched HTML slices and script/link destinations stay intact. Inline formatting may split a sentence across fields. Animated counters update their underlying numeric value.
- Title/description changes update existing Open Graph/Twitter equivalents. Keywords are saved as a meta tag. Structured-data scripts are preserved and may require separate developer maintenance if their wording changes.
- New pages use root `.html` slugs and the standard header/footer from `about.html`; links are added through the source page's editor. The handcrafted sitemap is not automatically changed.
- Version conflicts, failed saves and session expiry preserve the current tab's draft. Drafts are not automatically saved across browser closure. Copy a conflicting draft before reloading.
- Preview runs scripts inside an isolated sandbox. Forms, network actions and popups are restricted; use it to review layout/content, not contact/payment submission flows.
- Publishing uses a dashboard lock, content hashes, backups and atomic replacement. Coordinate external deployments so they do not write the same file during a publish.

## Tests and local preview

```powershell
php seo-manager/tests/unit.php
python seo-manager/tests/integration.py
```

Browser tests use Python Playwright and Chrome in an isolated temporary project copy, with disposable credentials. They now exercise the protected in-domain storage layout and confirm that private URLs cannot be fetched through the development router. They do not replace the live IIS checks. Screenshots/results are under `tests/artifacts`; exclude them from deployment.

The existing local server at `http://127.0.0.1:8088/seo-manager/` uses an explicit external development configuration, so it remains separate from production settings. The login credentials are unchanged. For another manual local server, use a separate development configuration via `SOLAR_SEO_PRIVATE`; never change the production origin to HTTP or deploy a development configuration. Manual local saves affect the local project.
