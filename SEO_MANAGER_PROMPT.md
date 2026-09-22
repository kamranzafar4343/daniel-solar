Build a simple SEO manager dashboard for this existing HTML/CSS website. Inspect the project and its hosting setup first, then use the site’s current design theme. Keep the SEO dashboard separate from any existing admin area, with its own login and SEO-only access.

The SEO person should be able to select any existing page, edit its visible text, meta title, meta description, and tags/keywords, then save and preview it. Keep the editor clear for a nontechnical person: compact fields for short text, larger fields for paragraphs, search to find text, and header/footer text in a separate collapsible section. Also let them create a page with a URL slug and add links to it through the interface—no routine FTP work.

Preserve each page’s layout, scripts, and existing links. Validate inputs, protect save actions, and prevent accidental overwrites when a page has changed. Check whether the live server can write page files **before choosing file-based storage**; if it cannot, use a suitable content storage and rendering approach for that hosting setup. Make published changes appear promptly despite caching.

Implement and test the full login, edit, save, create-page, link, and preview flows. Tell me exactly what needs deploying, how to create the SEO login, and any hosting setting required.
