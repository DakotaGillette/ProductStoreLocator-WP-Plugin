# Product Store Locator

A custom WordPress plugin that provides a Google Maps–based store locator for your product. It shows every configured store as a marker on a Google Map, supports ZIP/postcode search to recenter the map, and displays store details **only** inside marker info windows — there is no side list of stores.

- **Target:** WordPress 6.x, PHP 8.x
- **Text domain:** `product-store-locator`

## Features

- Custom post type `store_location` (private, admin UI only) under a top-level **Store Locator** menu.
- Settings page (Settings API) for the Google Maps API key (masked, with show/hide), map type, map style (presets + custom JSON), marker color, and a "Get directions" toggle. The map always auto-fits to your stores — no default center to configure.
- Visual usage meter and collapsed "Advanced" accordion for the cost/rate-limit controls.
- Lightweight built-in marker clustering for dense areas, and a click-to-center fix so popups never run off-screen.
- Import/Export: move all stores (with photos) between sites as one JSON file.
- Automatic updates via GitHub, using WordPress's native "Update available" flow.
- **Store Locator Details** metabox with a Google Places search that auto-fills address, coordinates, place ID, phone, and hours — all fields remain editable.
- Per-store visibility toggles for phone, hours, and about text.
- Frontend `[product_store_locator]` shortcode **and** a matching Gutenberg block (`Store Locator`).
- Rich map info windows: store photo (Featured Image), optional circular **store logo** badge, name, "Read More" description, **Call Us** and **Get Directions** buttons, phone, address, and a clickable hours panel with a live **Open / Closed** status for the current day.
- Responsive: on desktop the search box floats in the top-left corner of the map and store details open in an in-map info window; on mobile the search sits above the map and store details open in a **full-screen modal**.
- Optional **auto-locate**: on load, ask the visitor for their location and glide the map to their area (with a "you are here" marker) so nearby stores are visible.
- Assets load only on pages where the shortcode/block is present.

> **Live hours require structured data.** The "Open until 8 PM / Closed" status is computed from Google's structured opening hours + timezone, which are captured when you pick a store from the admin search. **Stores added before this feature must be re-searched once** (open the store, search + select it again, update) to populate the live status. Stores with only free-text hours still show the hours, just without the live open/closed badge.

## Installation

1. Copy this directory into `wp-content/plugins/product-store-locator/`.
2. Activate **Product Store Locator** in **Plugins**.
3. In the Google Cloud Console, create a project and enable the **Maps JavaScript API**, **Places API (New)**, and **Geocoding API**. Create an API key restricted to your domain.
4. Go to **Store Locator → Settings** and paste your API key, then adjust the map defaults.
5. Add stores under **Store Locator → Add New**. Use the "Search store on Google" box to auto-fill details, then publish.
6. Drop the shortcode `[product_store_locator]` (or the **Store Locator** block) onto any page.

### Shortcode attributes

| Attribute | Description                        | Default |
|-----------|------------------------------------|---------|
| `height`  | Map height in pixels               | `500`   |

Example: `[product_store_locator height="600"]`

### Where to place it

- **Block editor:** a "Shortcode" block, or the built-in **Store Locator** block.
- **WPBakery:** a native **Product Store Locator** element is registered in the builder (with a "Map height" field). Alternatively drop a Text Block / raw shortcode element and paste `[product_store_locator]`.
- **Classic editor / widgets:** paste the shortcode directly.

The shortcode and a copy button are also shown at the top of **Store Locator → Settings** for convenience.

## File structure

```
product-store-locator/
├── product-store-locator.php      Main bootstrap + activation hooks
├── uninstall.php                  Removes plugin options on uninstall
├── includes/
│   ├── class-plugin.php           Orchestrator + frontend store data
│   ├── class-cpt.php              store_location CPT + meta registration
│   ├── class-settings.php         Admin menu + Settings API page
│   ├── class-metabox.php          Store details metabox + save logic
│   ├── class-shortcode.php        Shortcode + frontend asset loading
│   ├── class-block.php            Dynamic Gutenberg block (reuses shortcode)
│   ├── class-api-guard.php        Server-side geocode proxy, caching, rate limits, caps
│   ├── class-import-export.php    Store export/import as a self-contained JSON file
│   └── class-updater.php          GitHub-backed auto-update checks
├── blocks/store-locator/
│   ├── block.json                 Block metadata
│   └── editor.js                  No-build editor script
└── assets/
    ├── js/psl-frontend.js         Frontend map, markers, info windows, search
    ├── js/psl-admin.js            Admin Places lookup + preview map
    ├── css/psl-frontend.css       Frontend styles (CSS variables)
    └── css/psl-admin.css          Admin metabox styles
```

## Moving stores between sites (Import / Export)

**Store Locator → Import / Export** lets you move stores between sites (e.g. staging → production) as a single JSON file.

- **Export** downloads every store — including its Featured Image and logo, embedded directly in the file (base64) — so the file is fully self-contained and works even if the source site later goes offline.
- **Import** uploads that file on another (or the same) site. Stores are matched by **Google Place ID** (or, if a store has none, by exact name): a match **updates** that store in place; anything unmatched is **added as new**. Existing stores not present in the file are left alone — import never deletes anything.
- Re-importing photos/logos adds fresh copies to the Media Library rather than reusing the previous ones (simplest and safest for a one-time migration; if you re-import repeatedly you may want to periodically clean up unused attachments).
- Both actions require `manage_options` and are nonce-protected.

## Automatic updates

The plugin checks its [GitHub repository](https://github.com/DakotaGillette/ProductStoreLocator-WP-Plugin) for a newer `Version:` header on the `main` branch (cached 12 hours) and, when one is found, shows WordPress's normal **"Update available"** notice on the Plugins page with a one-click **"Update Now"** — no separate update server, no WordPress.org listing required.

- A **"Check for updates"** link sits next to "Settings" on the plugin's Plugins-page row, for an on-demand check that bypasses the cache.
- Because the repo is public, no credentials are needed for this to work. (No API keys or secrets are ever stored in the plugin's code — only in this site's database — so making the repo public does not expose any secret.)
- **Release process**: bump `Version:` in `product-store-locator.php` (and the `PSL_VERSION` constant) and push to `main` — the next update check on any site running this plugin will pick it up automatically.

## Cost controls (avoiding surprise Google bills)

Because a Maps JavaScript key is necessarily public (it ships in the page), the defenses are layered — some in the plugin, some in Google Cloud. Do **all** of these:

### In the plugin (Store Locator → Settings → "Usage & Cost Controls")

- **Server-side geocoding.** ZIP/postcode searches are proxied through WordPress, so results are **cached** (a given ZIP is looked up from Google once, ever), **rate limited per visitor IP**, and subject to a **hard monthly cap**. This is the main abuse surface, and caching alone removes most of the cost.
- **Search Rate Limit** — max searches per visitor IP per minute (default 10). Stops button-spamming bots.
- **Monthly Geocoding Cap** — hard stop on geocoding calls per month (default 10,000). Cached lookups don't count.
- **Monthly Map-Load Cap** — best-effort stop on map loads per month (default 10,000). When hit, the map is hidden until the month rolls over. Note: this counter is incremented server-side, so views served by a **full-page cache** (caching plugin / Cloudflare) are not counted — treat this cap as a safety net and rely on the Google Cloud quota (below) as the authoritative ceiling for map loads.
- A **live usage readout** and a **Reset counters** button are on the settings page.

> Set the two caps at or below your current Google free allotment. Google's free tier changes over time, so check your account's current numbers and adjust.

### In Google Cloud (do these too — they are the real backstop)

1. **Restrict the Maps key by HTTP referrer** to your domain(s) only. This stops anyone from using your public key on other sites.
2. **Use a second key for geocoding**, restricted **by IP address** (your server), and put it in the "Geocoding API Key (server-side)" field. Referrer restrictions don't work for server-side calls; IP restriction is the correct control and keeps this key out of the browser entirely.
3. **Restrict each key by API** (Maps key → Maps JavaScript API only; geocoding key → Geocoding API only; the admin lookup also needs Places API (New) on whichever key the admin screen uses).
4. **Set per-API quota limits** in *APIs & Services → [API] → Quotas & System Limits* (e.g. Geocoding "Requests per day"). This is a Google-enforced hard ceiling independent of the plugin.
5. **Create a Cloud Billing budget + alerts** so you're emailed if spend approaches a threshold.

Together: the plugin caps + caches + rate-limits what it controls, and Google's key restrictions and quotas cap everything else.

### If you use Cloudflare

Cloudflare adds a strong outer layer, but mind two things:

- **Per-IP rate limiting needs the real visitor IP.** Behind Cloudflare, PHP's `REMOTE_ADDR` is often Cloudflare's edge IP, which would make the plugin's rate limit treat all visitors as one IP. Either confirm your host "restores the original visitor IP" (mod_remoteip), **or** tick **Settings → Usage & Cost Controls → "Behind Cloudflare"** so the plugin reads `CF-Connecting-IP`. Only enable that box if your **origin server is firewalled to accept traffic only from Cloudflare's IP ranges** — otherwise an attacker who finds your origin IP can spoof the header.
- **Add a Cloudflare Rate Limiting rule** on the search endpoint path (`/wp-json/psl/v1/geocode`) to block floods at the edge before they reach WordPress, and turn on **Bot Fight Mode / a Managed Challenge**. These stop most automated abuse for free.

What Cloudflare does **not** do: it can't cache or limit the server→Google geocoding calls (that traffic never passes through Cloudflare), and it won't stop a determined human from clicking search repeatedly. Those remain the plugin's job (caching + caps).

## Changelog

- **1.10.4** — Open-store zoom lock now keeps the info card centered/on-screen (the card opens upward, so the marker is placed lower in the viewport) and pans smoothly instead of snapping.
- **1.10.3** — Fixed the open-store center lock: recenter on `idle` (after the zoom settles) instead of `zoom_changed`, so cursor-anchored scroll zoom no longer drifts the store off-center. Panning still moves freely.
- **1.10.2** — Mobile store modal is now an inset, rounded card centered over a darkened backdrop (instead of edge-to-edge full screen), and the hours panel auto-expands on mobile.
- **1.10.1** — While a store's info window is open, zooming now keeps that store centered ("hard lock"); closing the bubble restores free zoom/pan.
- **1.10.0** — Auto-locate the visitor on load (optional, in Settings) and glide the map to their area with a "you are here" marker; on mobile, tapping a store now opens a full-screen modal instead of a tiny in-map bubble; refined info-window/card design (more padding, larger type, softer buttons).
- **1.9.2** — ZIP/postcode search now glides to the result (stepped "fly-in" zoom) instead of an abrupt jump.
- **1.9.1** — Removed the `editorialSummary` lookup (rarely populated for small businesses and it bumped the admin call to Google's priciest tier); the About field is entered manually. Fixed store names/addresses showing raw HTML entities (e.g. `&#8217;`, `&#8211;`) on the map by decoding them to real characters; the admin also cleans the stored title on the next save.
- **1.9.0** — Import/Export: download all stores (including photos and logos) as one self-contained JSON file, and re-import it on another site — ideal for staging → production migration. Auto-updates: the plugin now checks GitHub for new versions and shows a normal WordPress "Update available" notice with a one-click "Update Now", plus a "Check for updates" link on the Plugins page.
- **1.8.0** — Visual usage-meter with progress bars on the settings page; API keys masked with a show/hide toggle; cost-control fields tucked into a collapsed "Advanced" accordion; removed the now-redundant default-center lat/lng setting (the map always auto-fits to your stores); clicking a marker now pans it into view so the popup can't run off-screen; map height is capped to fit the viewport; lightweight built-in marker clustering for dense areas (numbered bubbles, no external library).
- **1.7.0** — Auto-import a store photo from Google (once, into the media library); refined info-window design (site font, matching buttons with white icons, polished hours); mouse-wheel zoom without Ctrl; ZIP search recenters/zooms with client-side geocode fallback; default view fits all stores.
- **1.6.0** — Store name auto-fills from Google (hidden WP title field); About pulls from the Google editorial summary; phone/hours/about visibility default ON for new stores; Save/Publish button at the bottom of the store form.
- **1.5.0** — Optional per-store logo (media upload); mobile layout (search above the map on phones); version now bumps per feature.
- **1.4.0** — Elfsight-style info windows: store photo, Read More, Call Us / Get Directions buttons, live Open/Closed hours; search overlay on the map; taller default map.
- **1.3.0** — Purpose-built store editor (no block canvas); live autocomplete dropdown; shortcode copy box + WPBakery element; filemtime asset cache-busting.
- **1.2.0** — Cost/abuse controls: server-side cached + rate-limited + capped geocoding; monthly map-load cap; Cloudflare-aware rate limiting.
- **1.1.0** — Switched admin lookup to the new Google Places API; fixed the admin menu; "Settings" plugin-row link.
- **1.0.0** — Initial release.

## Security notes

- All output is escaped; all input is sanitized on save.
- Metabox saves are protected by a nonce and capability checks.
- Settings require the `manage_options` capability.
- Google Places and Geocoding calls happen client-side; PHP makes no external HTTP requests.
- Fields hidden by the per-store visibility toggles are stripped server-side and never sent to the browser.
