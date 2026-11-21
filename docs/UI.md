# DCW Engage UI conventions

One frame, one menu, one stylesheet. Public, member and staff pages differ only in colour tokens, menu items, footer and width.

## The frame
Every page starts with `engage_header([...])` and ends with `engage_footer()` (`includes/engage_frame.php`, `includes/engage_page.php`).

- Blue top bar: brand, optional page tools, one menu. Then `<main>` on the light page: optional back link, one `<h1>`, optional lead.
- Options: `title` (required), `heading`, `lead`, `lead_html`, `back`, `member`, `tools`, `wide`, `narrow`, `auth`, `page`, `head`, `referrer`, `noindex`, `description`, `image`, `image_alt`. They are documented above `engage_header()`.
- `back`: `['Label', '/href']`. Left out, public and member pages get "Home" and staff pages get nothing. `null` means no link.
- `head => false`: the page prints its own `<h1>` (dashboard, member home page).
- 403 pages: `engage_forbidden('message')`. Never print your own HTML page, except the 500 page in `init.php`, which must work when the rest is broken.
- Sign-in style pages: `'auth' => true`, then an `.auth-card`. Pages whose URL holds a token also pass `'referrer' => 'no-referrer'`.

## The menu
`engage_nav()` is the only menu. Its items live in `engage_nav_items()`, one list per audience (guest, member, staff) in one format. Pages never build their own menu: pass `engage_member_menu($member, ['current' => '/path'])` as `tools`, or nothing.

## Styles
- All CSS is in `assets/css/engage.css`. `assets/css/staff.css` only holds the staff colour tokens and staff-only components.
- No `<style>` blocks and no `style=""` colours in `views/` or `includes/`. Use the tokens (`--primary`, `--accent`, `--leaf`, `--gold`, `--amber`, `--muted`, `--border` ...). Hard-coded hex colours are allowed only in print and email templates (cards, receipts, mail), where CSS variables do not work.
- Page-specific CSS goes in `engage.css` as `body.pg-<name> { ... }`, and the page passes `'page' => '<name>'` to `engage_header()`.
- Audience hooks on `<body>`: `aud-public`/`aud-member`/`aud-staff` (staff also has `admin`). Use them for token changes only, never to restyle a component.

## Adding a page
1. `engage_header([...])` with a `title`, a `back` link if it is not a top-level page, and `wide` if it holds tables.
2. Use the existing components (`.fcard`, `.field`, `.tbl`, `.alert`, `.tile`, `.qcard`, buttons). Do not invent a new one for a single page.
3. `engage_footer()`.
