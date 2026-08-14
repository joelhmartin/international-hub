# Anchor Private File Manager — Update Architecture

This plugin pulls its own updates from GitHub and surfaces them in the normal
WordPress updates UI, using the same setup as the Anchor Tools plugin: the
YahnisElsts Plugin Update Checker (PUC) library plus a tag-triggered GitHub
Actions release build.

## Overview
- Library: `yahnis-elsts/plugin-update-checker` (autoloaded from `vendor/`).
- Source repo: `https://github.com/joelhmartin/international-hub/`.
- Configured branch: `main`.
- Release build: `.github/workflows/release.yml`, triggered by pushing a tag.

## How a version is detected
`setBranch('main')` puts PUC into its release-aware mode. It tries, in order:

1. The latest **GitHub release** — this is the path we use.
2. Failing that, the **tag** with the highest version number (zipball of the tag).
3. Failing that, the `main` branch itself.

`enableReleaseAssets()` then makes it download the ZIP attached to the release
rather than GitHub's auto-generated zipball, so the package is exactly what the
workflow built. PUC's `upgrader_source_selection` filter renames the extracted
directory to the plugin's installed folder, so the folder name inside the ZIP
does not have to match the live install.

The version PUC compares against is the `Version:` header in
`anchor-private-file-manager.php`. A tag whose code carries a header version
lower than or equal to the installed one will not offer an update.

## Release workflow
1. Bump **both** version markers in `anchor-private-file-manager.php`:
   - the `Version:` plugin header, and
   - `const VERSION` on the main class.
   They must match — the header drives WordPress, the constant drives the
   plugin's own DB upgrade routine.
2. Commit and push to `main`.
3. Tag and push the tag:
   ```
   git tag 2.10.1
   git push origin 2.10.1
   ```
4. The `Release` workflow runs on the tag: installs Composer production
   dependencies, runs `php tests/run.php`, builds
   `anchor-private-file-manager.zip` (inner folder
   `anchor-private-file-manager/`, dev files stripped), and publishes a GitHub
   release with that ZIP attached and auto-generated notes.
5. WordPress picks it up on its next update check.

Tag format must match `[0-9]+.[0-9]+.[0-9]+*` or the workflow will not fire.

## Authentication (optional)
The repo is public, so no token is required. For a private repo or to raise the
GitHub API rate limit, supply a token via any of:

- a `.env` file in the plugin root containing `GITHUB_ACCESS_TOKEN=...`
  (loaded through Dotenv when the file exists),
- a `GITHUB_ACCESS_TOKEN` environment variable, or
- a `GITHUB_ACCESS_TOKEN` PHP constant.

Never commit `.env`; the release build excludes it.

## Forcing an update check
- WP Admin → Dashboard → Updates → "Check Again".
- Or delete the `puc_*` rows PUC stores in the options table.

## Assets and caching
Front-end CSS/JS are enqueued with `filemtime()` as the version string, so a
released change to `assets/` busts the browser cache without a version bump.
