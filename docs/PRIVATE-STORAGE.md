# Private file storage location

## Two constraints, not one

The store holds client documents and must be served only through the plugin's
authenticated PHP proxy (`admin-ajax.php?action=anchor_fm_stream`). Getting
that right means satisfying **both** of these at once:

1. **Nginx must not serve the directory.** The per-folder `.htaccess`
   containing `Deny from all` that this plugin writes is inert on Nginx, which
   Kinsta runs. A store under `wp-content/uploads/` with default permissions is
   downloadable by any anonymous visitor who knows the URL — no login, nothing
   in the activity log.
2. **PHP-FPM must be able to read the directory.** Kinsta confines FPM with
   `open_basedir`. Anything outside that list is invisible to PHP:
   `file_exists()` returns `false`, `is_readable()` returns `false`, and the
   plugin 404s every download and preview on the site.

Satisfying (1) by moving the store outside the web root breaks (2). That is not
hypothetical — see the incident below.

## What Kinsta's open_basedir actually allows

Measured on tmjtherapycentre.com, 2026-08-27:

```
/www/<site>/public
/www/<site>/mysqleditor
/www/<site>/web            (does not exist)
/www/<site>/deploy         (does not exist)
/www/<site>/deployment     (does not exist)
/www/<site>/deployments    (does not exist)
/www/<site>/tmp
/usr/share
/tmp
/dev/urandom
```

`/www/<site>/private` is **not** on that list. Neither is any other sibling of
`public/` that actually exists. On Kinsta there is no path that is both outside
the web root and readable by PHP — so the store has to live under `public/` and
be blocked at the Nginx and filesystem layers instead.

Check it yourself before assuming; the list is host-specific:

```php
// in a PHP file requested over HTTP -- NOT via WP-CLI, which has no open_basedir
echo ini_get('open_basedir');
```

## The arrangement that works

**1. Point the store at a dot-prefixed directory under `wp-content/`** — in
`wp-config.php`, above the `/* That's all, stop editing! */` line:

```php
define( 'ANCHOR_FM_STORAGE_DIR', ABSPATH . 'wp-content/.anchor-private-files' );
```

**2. Lock the directory to the PHP-FPM user:**

```bash
chmod 700 public/wp-content/.anchor-private-files
```

Two independent guards, both verified on the live site:

| Guard | Mechanism | Result |
|---|---|---|
| Leading dot | Nginx denies any URI containing a `/.` segment | `403` |
| Mode `0700` | Nginx runs as `www-data`, PHP-FPM as the site user, so the worker cannot stat the tree | `404` |

Either alone is sufficient. Both are deliberate: if Kinsta's "reset file
permissions" tool is ever run it will chmod the tree back to `0755`, and the
dot-prefix deny is what still stands between the documents and the public.
Restore the mode afterwards.

Confirm the FPM and Nginx users differ before relying on the mode guard:

```bash
ps aux | grep -E 'nginx|php-fpm' | grep -v grep
```

**3. Move existing files.** Defining the constant only changes where the plugin
looks — it relocates nothing.

```bash
mv public/wp-content/uploads/anchor-private-files public/wp-content/.anchor-private-files
chmod 700 public/wp-content/.anchor-private-files
```

## Verifying a site is safe

WP-CLI is **not** sufficient on its own: it runs without `open_basedir`, so it
will happily confirm a store that PHP-FPM cannot see. Every check below that
matters is made over HTTP.

```bash
# 1. Where does the plugin think the store is, and can FPM read it?
#    Put this in public/<random>.php, request it over HTTPS, then delete it.
<?php
require_once __DIR__ . '/wp-load.php';
header('Content-Type: text/plain');
global $wpdb;
$b = Anchor_Private_File_Manager::storage_base();
echo "storage_base=$b\nis_dir=", (int) is_dir($b), "\nopen_basedir=", ini_get('open_basedir'), "\n";
$rows = $wpdb->get_results("SELECT id, folder_id, stored_name FROM {$wpdb->prefix}anchor_fm_files");
$miss = 0;
foreach ($rows as $r) {
    $p = "$b/" . (int) $r->folder_id . "/" . $r->stored_name;
    if (!file_exists($p) || !is_readable($p)) { $miss++; echo "MISSING {$r->id}\n"; }
}
echo "$miss of ", count($rows), " rows unreadable\n";
```

```bash
# 2. The store must not be reachable over HTTP -- expect 403 or 404, never 200.
curl -s -o /dev/null -w '%{http_code}\n' \
  https://<site>/wp-content/.anchor-private-files/<folder_id>/<stored_name>

# 3. The old public location must be gone.
curl -s -o /dev/null -w '%{http_code}\n' \
  https://<site>/wp-content/uploads/anchor-private-files/<folder_id>/<stored_name>
```

Both a "0 rows unreadable" from (1) **and** a non-200 from (2) are required. One
without the other is the failure mode described next.

## Incident: 2026-08-21 to 2026-08-27, tmjtherapycentre.com

The store was moved to `/www/<site>/private/anchor-private-files` to get it out
of the web root — which fixed the exposure and broke every download on the
site, because `private/` is outside `open_basedir`. For six days
`ajax_stream()` answered every request with a bare `404`:

- All 768 files were affected, not a subset.
- The access log shows the failures plainly (`anchor_fm_stream ... 404`), across
  many customers and many files, all referred from `/my-account/`.
- Nothing was written to any error log, because the endpoint returned a status
  code and no body.
- WP-CLI reported the files present and readable the whole time. It runs without
  `open_basedir`, so it could not reproduce the failure.
- The preview modal rendered name, type and size normally — that data comes from
  the DB row — so the symptom users saw was a blank white pane and a Download
  button that led to a browser 404 page.

Fixed by moving the store to `public/wp-content/.anchor-private-files` at mode
`0700`, verified end to end with an authenticated request returning `200` and a
byte-identical SHA-1.

Hardened afterwards, so a recurrence is one `grep` away rather than a bisect:

- `ajax_stream()` logs every refusal with its reason, the resolved path, and the
  active `open_basedir` (`Anchor FM: refused to stream file N — ...`).
- `ajax_preview()` and `ajax_pd_my_docs()` resolve the file on disk and return
  `available: false` when the bytes are unreachable, so the UI shows "could not
  be read from the server" and withholds the Download button instead of
  rendering an empty viewer.

## Known gap

`storage_base()` still **defaults** to `wp-content/uploads/anchor-private-files`
with whatever permissions the filesystem gives it. A fresh install on an Nginx
host with no constant defined is insecure by default. The `.htaccess` and
`index.php` the plugin writes stop directory listing on Apache and stop nothing
on Nginx.

Making the default safe — a dot-prefixed directory at `0700`, which needs no
constant and no web-server config — is not done yet, and is the obvious fix now
that the arrangement is known to work.
