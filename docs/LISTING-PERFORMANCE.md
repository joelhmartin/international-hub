# Why opening a folder takes about a second

Measured on tmjtherapycentre.com, 2026-08-27, over HTTPS as a logged-in
administrator. Every number below is a real request against the live site.

## The measurement

| Request | Time |
|---|---|
| `admin-ajax.php` with an **unregistered action** (boots WordPress, does nothing, returns `0`) | **1158 ms** |
| `anchor_fm_list`, folder 78 (31 files) | **1027 ms** |
| `anchor_fm_list`, folder 100 (a handful of rows) | **1049 ms** |
| `anchor_fm_list`, root | **1151 ms** |
| Static asset, no PHP | 82 ms |
| Cached front page | 98 ms |

A request that runs **none** of this plugin's code costs the same as one that
lists a full folder. The folder size does not measurably change the time.

## Where the second goes

```
wp-load total  : 956 ms
active plugins : 54
object cache   : NONE - database only
autoloaded opts: 816 rows, 349.1 KB
queries so far : 193      <- before any plugin code of ours runs
```

Booting WordPress and 54 plugins — WooCommerce, Subscriptions, LearnDash,
Wordfence, WP Security Audit Log, Divi and the rest — costs ~956 ms on every
`admin-ajax.php` call. The listing work itself is single-digit milliseconds.

**It is not loading file contents.** `ajax_list()` selects names, sizes, MIME
types and timestamps. Bytes are read only by `ajax_stream()`, when someone
actually opens a file.

## What was fixed in the plugin (2.13.3)

Two changes, neither of which can touch the 956 ms:

**1. Don't issue the request twice.** Children are cached per folder and kept
across collapse, so re-expanding is instant, and the fetch starts on hover of
the disclosure arrow — overlapping the wait with the time between the pointer
arriving and the click landing. A cold expand also spins the arrow now, instead
of looking like a missed click for a second.

**2. Removed an N+1 in permission resolution.** Every row resolves a capability,
and each resolution walked that row's ancestor chain running three queries per
level — repeated once per sibling. A memo scoped to read-only listing endpoints
(`begin_perm_memo()`) collapses the shared work:

| Subscriber listing folder 78 (31 files) | Queries | Time |
|---|---|---|
| Before | 497 | 37.1 ms |
| After | 136 | 8.4 ms |

Verified identical: 3,072 permission decisions across 4 users, 0 mismatches.
Administrators were never affected — `get_effective_capability()` short-circuits
on `user_can('administrator')`, so an admin listing costs 0 permission queries.

The remaining 136 are ~4 per file (row fetch, user grant, policy, role grant)
and could be batched into a handful. At 8 ms against a 956 ms bootstrap there is
no reason to.

## What would actually make it fast

The plugin cannot fix a cost it does not incur. In order of leverage:

1. **Enable Redis object caching** (MyKinsta → Tools, plus a drop-in). The site
   currently has no persistent object cache, so 816 autoloaded options and 193
   bootstrap queries are re-read from MySQL on every request. This speeds up
   every logged-in page on the site, not just this plugin.
2. **Audit the 54 active plugins.** Several are heavy and load on every request
   including AJAX. Profiling which ones cost the most on `admin-ajax.php` is the
   next step; Kinsta APM or Query Monitor will name them.
3. Reducing the autoloaded options payload (349 KB) is a smaller, easy win.

None of these are plugin changes, and all of them help the whole site.
