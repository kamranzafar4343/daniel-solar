# Daniel Solar Engineering & Design — Premium Digital Business Card

A single-page, premium digital business card for **Daniel Solar Engineering &
Design**, built with plain HTML5, CSS3, and vanilla JavaScript only (no
frameworks). Open `index.html` in any browser — no build step, no server,
no dependencies to install.

## Source of content

Every piece of text, service name, contact detail, and statistic on the
card was taken directly from the official website,
**https://www.danielsolared.com/** (home, About, and Contact pages).
Nothing was invented.

| Card element | Source on the website |
|---|---|
| Company name | "Daniel Solar Engineering & Design" — site header |
| Tagline | "Permit-Ready Solar Design" — page title suffix |
| Sub-tagline | "Nationwide Solar Engineering · California Expertise" — hero eyebrow |
| Professional badge | "PE LICENSED" stat + "stamped by licensed P.E.s across all 50 U.S. states" |
| Phone | +1 (858) 422-6880 — header / footer |
| Email | info@danielsolared.com — decoded from the site's obfuscated mailto link |
| Website | https://www.danielsolared.com/ |
| 7 services listed | PV System Design, Permit Plan Sets & PE Stamps, Structural & Electrical Review, Interconnection & Utility Applications, Commercial & Utility-Scale Design, EV Charging Design & Engineering, Electrical Design — homepage "What We Engineer" |
| Why Choose Us badges | 24–48 Hrs turnaround, PE Licensed, 10,000+ Projects Designed, Licensed across 40+ regions — homepage stat bar & footer |
| Request Quote link | Contact page, https://www.danielsolared.com/contact.html |
| Footer copyright | "© 2026 Daniel Solar Engineering and Design. All rights reserved." — site footer |

No addresses, awards, certifications beyond the stated PE licensing, or
social media links were listed on the site, so none appear on the card.

## Project structure

```
DanielSolar-DigitalBusinessCard/
├── index.html            # Card markup
├── css/
│   └── style.css         # All styling (white + gold, glassmorphism, animation)
├── js/
│   ├── script.js         # QR code generation + scroll reveal
│   └── qrcode.min.js     # Vendored MIT-licensed QR encoder (davidshimjs/qrcodejs)
├── assets/
│   ├── logo.svg           # Company logo (sun + solar-panel mark)
│   └── icons/              # Service, badge, and action-button icons (SVG)
└── README.md
```

## Design

- **Palette:** ivory/white base (`#FBF9F4`, `#FFFFFF`) with a gold accent
  system (`#E7C578` → `#C9A24B` → `#9C7A2E`) and a deep charcoal ink
  (`#1B1712`) matching the official site's own theme color.
- **Type:** Cormorant Garamond (serif display) for the company name and
  tagline, paired with Manrope (sans) for body copy and UI labels.
- **Card:** centered, rounded 28px corners, soft layered shadow, subtle
  glass/gradient surface, shimmering top/bottom hairlines, gentle
  entrance and hover animations — fully responsive from mobile (95%
  width) up to desktop (400px max width).
- **QR code:** generated live in the browser (no external image) and
  points to `https://www.danielsolared.com/`.

## Running it

Just double-click `index.html`, or open it from any static file server.
It requires no internet connection once the fonts have loaded once
(only Google Fonts are loaded remotely; everything else is local).

## Sharing

The whole folder is self-contained, so it can be zipped and shared, or
the `index.html` can be uploaded to any static host (e.g. Google Drive,
Netlify, GitHub Pages) and shared as a link or QR code.
