# Folio Take-Home — Writeup

## The Problem

Folio is a minimal PHP/SQLite internal document-sharing app. Staff create documents and share them with recipients via one-time links (opaque hex tokens). Three customer-requested features needed to be added:

1. **Scheduled Publishing** — Prepare documents in advance; they become visible only at a set date/time.
2. **Human-Readable Document IDs** — Short, speakable identifiers instead of auto-increment integers.
3. **Share by Name** — Search documents by title instead of scrolling a list.

## What I Built

All three features are implemented and tested (17 tests, all passing).

### Migration System

**Approach:** Numbered PHP files in `migrations/` that return closures receiving PDO. A `_migrations` table tracks applied files. Each migration runs in a transaction.

**Why closures over raw SQL?** Migration 002 needs to backfill slugs on existing rows — a data migration that benefits from PHP logic (calling `generate_slug()`). The runner integrates with `seed.php` so fresh clones get the full schema automatically.

### Feature 1: Scheduled Publishing

- Added nullable `publish_at` column (`NULL` = immediately published, backwards-compatible)
- `view.php` returns HTTP 403 with "not yet available" message when `publish_at` is in the future
- Staff can set publish time during creation or update it later via `schedule.php`
- Share page warns when creating links for not-yet-published documents
- All scheduling actions are audit-logged

**Design decision:** Used HTTP 403 (not 404) because the link *is valid* — the content just isn't available yet. This communicates "come back later" to recipients.

### Feature 2: Human-Readable Slugs

- Format: `{title-slugified}-{4-char-hex}` (e.g., `welcome-packet-f29e`)
- UNIQUE constraint prevents collisions
- Displayed as primary identifier in admin UI

**Key decision — slugs complement, don't replace, share tokens:**
- Share tokens provide per-recipient access control (revocable, trackable)
- Slugs provide human-friendly identification for staff conversation
- Knowing a slug does NOT grant document access — security preserved
- Trade-off accepted: recipient URLs still use opaque tokens (acceptable since recipients get links via email, they don't need to type them)

### Feature 3: Search by Title

- Substring match (`LIKE '%query%'`) on admin page, case-insensitive
- Index on `title COLLATE NOCASE` for performance

**Why substring over prefix or fuzzy?**
- Staff remember words *within* titles, not just beginnings
- SQLite LIKE is fast for this app's scale (hundreds of docs, not millions)
- Fuzzy matching adds complexity without clear benefit for an internal tool

## Things I Noticed in the Existing Code

- `current_staff()` hardcodes user ID 1 — no real authentication
- No CSRF protection on forms (acceptable for an internal tool, but worth noting)
- Schema uses TEXT for timestamps — works with SQLite's flexible typing but loses type safety
- No pagination on the document list

## What I'd Do With More Time

- Pagination for the document list
- FTS5 (SQLite full-text search) for ranked, multi-field search
- Timezone-aware UI (explicit timezone display, user preference)
- Slug collision retry with automatic suffix regeneration
- Staff-authenticated `/d/{slug}` route for quick navigation by slug
- Rate limiting on `view.php` to prevent token brute-force
- CSRF tokens on forms

## Agent Setup

Added `.cursorrules` with project conventions, patterns, and guardrails. This ensures AI assistance stays consistent with the codebase's existing style (prepared statements, `h()` escaping, audit logging, migration file format).

## File Changes

| File | Change |
|------|--------|
| `lib/migrate.php` | New — migration runner |
| `lib/bootstrap.php` | Added `generate_slug()`, `is_published()` |
| `migrations/001_*.php` | Adds `publish_at` column |
| `migrations/002_*.php` | Adds `slug` column + unique index + backfill |
| `migrations/003_*.php` | Adds title search index |
| `seed.php` | Runs migrations, varied sample data |
| `public/admin.php` | Search box, slug display, status badges |
| `public/schedule.php` | New — schedule management |
| `public/share.php` | Scheduling warning + slug reference |
| `public/view.php` | Publish gate (403 for future docs) |
| `public/assets/style.css` | Search, badges, action link styles |
| `tests/test.php` | 17 tests covering all features |
| `.cursorrules` | AI agent configuration |
