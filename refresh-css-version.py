"""Update the shared stylesheet URL after editing styles.css.

Run this before uploading changed HTML and CSS files to the server.
"""

from pathlib import Path
import hashlib
import re


ROOT = Path(__file__).resolve().parent
VERSION = hashlib.sha256((ROOT / "styles.css").read_bytes()).hexdigest()[:12]
STYLESHEET_LINK = re.compile(
    rb'(href=["\'][^"\']*styles\.css)(?:\?[^"\']*)?(["\'])', re.I
)

changed = 0
for page in ROOT.rglob("*.html"):
    original = page.read_bytes()
    updated = STYLESHEET_LINK.sub(
        lambda match: match.group(1) + b"?v=" + VERSION.encode() + match.group(2),
        original,
    )
    if updated != original:
        page.write_bytes(updated)
        changed += 1

print(f"CSS version: {VERSION}; HTML pages updated: {changed}")
