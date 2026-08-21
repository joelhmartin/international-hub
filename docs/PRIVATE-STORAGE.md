# Private file storage location

## The problem this exists to prevent

The plugin used to store uploaded client files under
`wp-content/uploads/anchor-private-files/`, protecting them with a per-folder
`.htaccess` containing `Deny from all`.

**That protection is inert on Nginx.** Kinsta runs Nginx, which never reads
`.htaccess`. On any Nginx host the store was served directly to anonymous
visitors — every file downloadable by anyone who knew or guessed the URL, with
no login and nothing in the plugin's activity log.

This was found and fixed on tmjtherapycentre.com on 2026-08-21. Any other site
running this plugin is still exposed until the two steps below are done there.

## Required on every site

**1. Point the store outside the web root** — in `wp-config.php`, above the
`/* That's all, stop editing! */` line:

```php
define( 'ANCHOR_FM_STORAGE_DIR', dirname( ABSPATH ) . '/private/anchor-private-files' );
```

On Kinsta, `dirname( ABSPATH )` is the site root and `public/` is the web root,
so `private/` is a sibling of `public/` and is unreachable over HTTP.

A filter is available if a site needs something different:

```php
add_filter( 'anchor_fm_storage_dir', function ( $path ) { return '/some/other/path'; } );
```

**2. Move the existing files.** Defining the constant only changes where the
plugin looks — it does not relocate anything. Move the whole tree, preserving
the `<folder_id>/<stored_name>` layout, then delete the old directory:

```bash
mv public/wp-content/uploads/anchor-private-files private/anchor-private-files
```

## Verifying a site is safe

```bash
# 1. Where does the plugin think the store is?
wp eval 'echo Anchor_Private_File_Manager::storage_base();'
#    -> must NOT be under the web root

# 2. Is the old public directory gone?
ls -d public/wp-content/uploads/anchor-private-files   # -> should not exist

# 3. Does every DB row still resolve on disk?
wp db query "SELECT CONCAT(folder_id,'/',stored_name) FROM wp_anchor_fm_files;" --skip-column-names \
  | while read rel; do [ -n "$rel" ] && [ -f "$(wp eval 'echo Anchor_Private_File_Manager::storage_base();')/$rel" ] || echo "MISSING: $rel"; done
```

Then confirm over HTTP that an old URL is dead — expect 403 or 404, never 200:

```bash
curl -s -o /dev/null -w '%{http_code}\n' \
  https://<site>/wp-content/uploads/anchor-private-files/<folder_id>/<stored_name>
```

## Known gap

`storage_base()` still **defaults** to the old path under `wp-content/uploads`.
A fresh install on an Nginx host with no constant defined is therefore insecure
by default. The `.htaccess` and `index.php` the plugin writes stop directory
listing on Apache but stop nothing on Nginx.

Making the default safe, or having the plugin detect that its store is
web-reachable and raise an admin notice, is not done yet.
