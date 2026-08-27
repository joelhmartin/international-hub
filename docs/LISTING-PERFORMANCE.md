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

```text
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

## What changed in 2.14.0: the round trip is gone

The 2.13.3 notes above stop at "don't pay the cost twice". 2.14.0 removes the
request instead.

**Why one was needed at all.** It wasn't, really. The sidebar tree already
expands with no request — `ajax_bootstrap` ships all 134 folders and
`toggleNode()` flips client state. Only the file-list row disclosure went to the
server. Same app, same data, opposite strategy, and no principle behind where
the line fell.

What made the lazy design look necessary was the cost of listing in bulk:

```text
resolving all 134 folders + 768 files for a subscriber
  2.13.3 (per-entity memo)   : 3609 queries   694 ms
```

The resolver asked the database about one entity at a time — three queries per
entity, per ancestor level, per row — against a permissions table containing
**32 rows in total**. Listing everything really was expensive, so the UI paged
it by folder. Both halves of that reasoning were wrong by 2026: a request costs
~1 s of bootstrap no matter how little it returns, and the whole store fits in
a single response.

**The fix, in two parts.**

1. `Anchor_FM_Permission_Index` loads the permission model — folders,
   permission rows, policies, and each entity's folder — in **five queries**,
   and the existing resolver reads from it instead of the database. The
   algorithm is untouched; only the data source moved.

   ```text
   2.13.3 (per-entity memo)   : 3609 queries   694 ms
   2.14.0 (batched index)     :    6 queries    81 ms     601x fewer, 9x faster
   ```

2. Every listing response now carries a `contents` map — every folder this user
   may open, permission-filtered. Expanding a folder makes **no request at
   all**. It is sent on every listing, which is also what keeps it correct: the
   client's copy is never older than the navigation that fetched it, so there
   is no invalidation to get wrong. Above
   `anchor_fm_contents_preload_max` rows (default 5000) it is omitted and the
   client falls back to fetching per folder.

   The same ceiling gates the index's per-entity data, which is the only part
   of it that grows with how much a site stores. Above the limit those maps are
   not built at all and the resolver falls back to a row query per entity —
   otherwise the "fallback for large stores" would still have loaded an array
   entry per file before deciding not to use it. The ceiling is tested with a
   bounded `LIMIT`ed subquery rather than `COUNT(*)`, so the check does not
   itself become the expensive operation on a large store. Folders, permission
   rows and policies load unconditionally: they are the permission model, and
   they scale with how a site is organised rather than with what it holds.

   ```text
   same data, ceiling forced to 10 (simulating a store past the limit)
     normal   :  15 queries   93 ms   contents built
     fallback : 772 queries  167 ms   contents omitted
   902 decisions compared across the two paths, 0 mismatches
   ```

**Cost.** The listing response grows from ~5.6 KB to ~177 KB uncompressed, which
the server sends `content-encoding: br` — about 35 KB on the wire by gzip
measure, less under Brotli. The request itself stays at **~1.05 s**, unchanged:
the extra work disappears into the bootstrap it already shares a request with.
One compressed response replaces an unbounded number of 1 s round trips.

**Failing safely.** `wpdb::get_results()` returns an empty array both for "no
rows" and for "the query failed", so every load in the index checks
`$wpdb->last_error` to tell them apart. An index built from a failed query is
not empty-but-correct, it is confidently wrong — it would answer "no
permissions exist" and deny every non-admin on the site. On failure the index
is abandoned, or the affected entity type is left untracked, and the
per-entity queries run instead: slower, and right. Verified by pointing the
bulk video query at a table that does not exist — the user still sees all 115
videos through the row-query fallback, rather than none.

**How it was checked.** Permission code is where a "faster" rewrite quietly
starts showing people other people's files, so the batched resolver was
compared against the shipped one across every entity on the live site:

- **191,896 decisions, 0 mismatches** — 68 users (one per distinct capability
  set on the site, plus user id 0) against all 134 folders, 768 files, 115
  videos and 1 link, comparing view, manage, and the full capability string.
- Existing `ajax_list` output verified **byte-identical** on seven folders;
  `contents` is the only added key. `ajax_bootstrap` byte-identical.
- The preload was checked against the permission predicates in both
  directions, for all 68 users: **22,922 preloaded rows inspected, 0
  over-sharing** (nothing in a user's map that `can_user_view_*` denies them,
  no folder keyed that they cannot view) and **0 under-sharing** (nothing the
  per-folder endpoint would have shown them is missing). Also spot-checked over
  HTTP as a real logged-in non-admin across all 93 folders in their preload.
- 17 unit tests on the index itself, including the MySQL `_ci PAD SPACE`
  matching it has to mirror.
- **The client half is tested too**, which the server checks above say nothing
  about: `tests/client-expand.js` runs the real `file-manager.js` under a
  stubbed jQuery and asserts on requests issued — a preloaded folder expands
  with **no** request, a cold one asks exactly once, re-expanding after collapse
  asks zero times, a click during an in-flight prefetch joins it rather than
  duplicating it, and a fetch that outlives a navigation does not seed the cache
  that navigation cleared. Each assertion was mutation-checked by breaking the
  guard it covers and confirming it fails; one early test proved vacuous that
  way and was replaced.

What none of this covers is rendering — that the expanded rows actually paint,
that the disclosure arrow spins, that the drawer looks right. Those need a
human or a real browser.

## What would actually make it fast

Measured, after an initial wrong guess. The first version of this document
named Redis object caching as the top lever. That was wrong, and the number
that disproves it is worth keeping:

```text
bootstrap total    : 1021.9 ms
  of which SQL     :   27.6 ms  (191 queries)
  PHP parse/execute:  994.3 ms
opcache            : enabled
object cache       : none
```

**SQL is 2.7% of the bootstrap.** A perfect object cache — eliminating every
one of the 191 queries, which no cache does — would save under 28 ms of a
1022 ms request. Redis is not the lever here. Neither is trimming the 349 KB of
autoloaded options, which is part of that same 27.6 ms.

The 994 ms is PHP executing 54 plugins' bootstrap code on every request, with
opcache already enabled so it is not parse cost. That is the only thing worth
attacking:

1. **Find out which plugins cost the most on `admin-ajax.php`.** 54 are active,
   several of them heavy suites (WooCommerce + Subscriptions, LearnDash,
   Wordfence, WP Security Audit Log, Divi, FunnelKit). Kinsta APM, or a
   `microtime()` probe around each plugin include, will name them in an hour.
2. **Stop loading what AJAX does not need.** Many plugins hook `init` and load
   their full stack on every `admin-ajax.php` call, including ones that have
   nothing to do with them. Some can be short-circuited for our own actions.
3. **Deactivate what is not used.** `wp-file-manager` in particular overlaps
   this plugin's job, and there are three FunnelKit/marketing-automation pairs
   plus two Divi module packs.

Only (1) is worth doing first: it converts a guess into a list. Everything
above is site-level, not plugin-level — this plugin's own share of a folder
listing is single-digit milliseconds.
