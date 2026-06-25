# QR Redirect Manager

A WordPress plugin that lets you create dynamic QR code redirects. Each QR code points to a fixed URL on your site, and you control where it actually sends visitors — updatable anytime without reprinting the QR image.

## Features

- **Dynamic redirects** — QR codes point to `yoursite.com/go/?code=ABC123`; change the destination anytime from the admin
- **1-to-1 code mapping** — each code maps to exactly one destination (WordPress page/post or any custom URL)
- **QR image generation** — generate and download QR code PNG images directly from the WordPress admin
- **Scan tracking** — logs every scan with date, time, IP address, and user agent
- **Diagnostics page** — built-in tool to verify database tables and test write/read operations
- **Homepage fallback** — unknown or unconfigured codes redirect to the homepage

---

## Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher
- MySQL 5.7 or higher

---

## Installation

1. Download the latest release zip from the [Releases](../../releases) page
2. In your WordPress admin go to **Plugins → Add New → Upload Plugin**
3. Upload the zip file and click **Install Now**, then **Activate**
4. Go to **Settings → Permalinks** and click **Save Changes** — this flushes rewrite rules so `/go/` URLs are recognized by WordPress (one-time step)

---

## Usage

### Creating a QR Redirect

1. In the WordPress admin sidebar click **QR Redirects → Add QR Code**
2. Enter a **Label** (internal reference, not shown to visitors)
3. Enter a **Code** — lowercase letters, numbers, and hyphens only (e.g. `summer-2025`). **This cannot be changed after saving** as it gets encoded into the printed QR image
4. Choose a **Destination**:
   - *WordPress Page / Post* — select from a dropdown of all published pages and posts
   - *Custom URL* — any internal or external URL
5. Click **Save Redirect**
6. The QR panel on the right shows a live preview and a **Download QR Image** button

### Your QR URL format

```
https://yoursite.com/go/?code=your-code-here
```

Use any QR code generator (or the built-in one) and encode this URL into your QR image. Print it. Done.

### Updating the Destination

1. Go to **QR Redirects** in the admin sidebar
2. Find the redirect and click **Edit**
3. Change the destination page or URL
4. Click **Save Redirect**

The printed QR code continues to work — only the destination changes.

---

## Diagnostics

If saves aren't working or redirects aren't firing, use the built-in diagnostics tool:

1. Go to **QR Redirects → ⚙ Diagnostics**
2. The page checks:
   - Whether both database tables exist
   - Whether a test write/read to the database succeeds
   - Database connection details and last error
   - Whether the `/go/` rewrite rule is active

If tables are missing, click **Create Tables Now** directly from the diagnostics page.

---

## How It Works

### Redirect Flow

```
QR scan → yoursite.com/go/?code=ABC123
        → WordPress rewrite rule matches /go/
        → template_redirect hook fires
        → Plugin looks up code in database
        → Logs the scan (datetime, IP, user agent)
        → 302 redirect to configured destination
```

If the code is unknown or has no destination set, the visitor is redirected to the homepage.

### Database Tables

The plugin creates two custom tables on activation:

**`{prefix}qrm_codes`** — stores code → destination mappings

| Column | Type | Description |
|---|---|---|
| `id` | INT | Auto-increment primary key |
| `code` | VARCHAR(100) | Unique URL parameter value |
| `label` | VARCHAR(255) | Admin display name |
| `dest_type` | VARCHAR(10) | `page` or `url` |
| `page_id` | BIGINT | WordPress post/page ID (if dest_type = page) |
| `custom_url` | TEXT | Destination URL (if dest_type = url) |
| `created_at` | DATETIME | Record creation timestamp |
| `updated_at` | DATETIME | Last updated timestamp |

**`{prefix}qrm_scan_logs`** — stores scan history

| Column | Type | Description |
|---|---|---|
| `id` | INT | Auto-increment primary key |
| `code_id` | INT | Foreign key to qrm_codes |
| `scanned_at` | DATETIME | Scan timestamp |
| `ip_address` | VARCHAR(45) | Visitor IP (supports IPv6) |
| `user_agent` | VARCHAR(500) | Visitor browser/device string |

---

## File Structure

```
qr-redirect-manager/
├── qr-redirect-manager.php   # Main plugin file, activation hooks
├── includes/
│   ├── database.php           # Table creation, all CRUD functions
│   ├── redirect.php           # Rewrite rule registration, redirect handler
│   └── admin.php              # All admin UI: list, add/edit, logs, diagnostics
└── assets/                    # Reserved for future static assets
```

---

## Frequently Asked Questions

**Can I change a code after printing?**
No — the code is encoded into the QR image. Codes are locked after saving. You can always change the *destination* freely.

**What happens if a visitor scans an old QR with a code that was deleted?**
They are redirected to the homepage.

**Can I use external codes from a print vendor?**
Yes — create the code entry in the admin before the QR images go to print, using the exact code value the vendor will encode.

**Does changing the destination affect the QR image?**
No — the QR image encodes the fixed `/go/?code=` URL. The destination is resolved server-side on every scan.

**How many redirects can I create?**
Unlimited.

---

## Changelog

### 2.1.0
- Fixed form saves using `admin-post.php` to avoid headers-already-sent errors
- Fixed redirect handler using proper WordPress rewrite rules and `template_redirect` hook
- Separated `dbDelta()` calls per table (fixes silent table creation failures)
- Added explicit format arrays to all `$wpdb` calls
- Added diagnostics page with live write/read test
- Added "Create Tables Now" button for environments where activation hook doesn't fire
- Added database error surfacing on failed saves
- Auto-recreates tables on `init` if missing (catches FTP uploads)

### 2.0.0
- Initial release with full admin UI, QR generation, scan logging

---

## License

GPL v2 or later — see [LICENSE](LICENSE)
