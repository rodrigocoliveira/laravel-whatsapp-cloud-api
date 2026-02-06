# Publishing Guide

## First-Time Setup (Packagist)

1. Ensure the GitHub repo is **public**
2. Go to [packagist.org](https://packagist.org) and sign in with GitHub
3. Click **Submit**, paste: `https://github.com/rodrigocoliveira/laravel-geoaddress`
4. Set up the **auto-update webhook** in GitHub repo Settings > Webhooks using the URL and token Packagist provides

## Releasing a New Version

### 1. Decide the version bump

Follow [Semantic Versioning](https://semver.org/) — format: `vMAJOR.MINOR.PATCH`

| Type      | When to use                                                        | Example            |
|-----------|--------------------------------------------------------------------|--------------------|
| **Patch** | Bug fixes, typos, internal changes (no breaking, no new features)  | `v1.0.0` → `v1.0.1` |
| **Minor** | New features that are backwards-compatible                         | `v1.0.0` → `v1.1.0` |
| **Major** | Breaking changes (renamed methods, removed features, changed API)  | `v1.0.0` → `v2.0.0` |

### 2. Commit any pending changes

```bash
git add -A
git commit -m "Prepare release vX.Y.Z"
```

### 3. Create and push the tag

```bash
git tag vX.Y.Z
git push origin main --tags
```

### 4. Create a GitHub Release

Requires `gh` CLI installed and authenticated (`gh auth login`).

```bash
gh release create vX.Y.Z --title "vX.Y.Z" --generate-notes
```

The `--generate-notes` flag auto-generates release notes from commits since the last tag.

### 5. Verify on Packagist

Check [packagist.org/packages/multek/laravel-geoaddress](https://packagist.org/packages/multek/laravel-geoaddress) — the new version should appear instantly (webhook is configured).

## Installing in Laravel Apps

```bash
# Latest version
composer require multek/laravel-geoaddress

# Specific version
composer require multek/laravel-geoaddress:^1.0

# Update to latest
composer update multek/laravel-geoaddress
```

## Quick Reference

```bash
# See all existing tags
git tag

# See latest tag
git describe --tags --abbrev=0

# Delete a tag locally + remote (if you tagged wrong)
git tag -d vX.Y.Z
git push origin :refs/tags/vX.Y.Z
```

## Claude Code — Release Commands

Ask Claude Code to handle the release process:

- `"Create a patch release"` — commits pending changes, bumps patch (e.g. v1.0.1 → v1.0.2), creates tag, and pushes
- `"Create a minor release"` — same but bumps minor (e.g. v1.0.2 → v1.1.0)
- `"Create a major release"` — same but bumps major (e.g. v1.1.0 → v2.0.0)

Claude Code will:
1. Check the latest tag to determine the next version
2. Commit any pending changes
3. Create and push the git tag
4. Create a GitHub Release with auto-generated notes via `gh release create`
