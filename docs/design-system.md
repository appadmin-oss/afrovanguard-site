# Afrovanguard Design System

The single source of truth for design decisions is **`assets/site/tokens.css`**. This
document explains the token layer, the theming contract, and the playbook for
migrating each module onto it. It is the first executed step of the roadmap in
`DESIGN-AUDIT.md` (Critical items #1–#4).

## Why

Before tokens, the same concepts were re-invented per module: the brand gold
existed in ~7 hex values across 7 namespaces (`--ch-*`, `--color-*`, `--m-*`,
`--p-*`, `--auth-*`, `--cc`, `--gold*`), the body font was defined as *both*
Montserrat and Inter, and each module shipped its own theme logic. Tokens fix
this at the root: define once, reference everywhere.

## The token layer (`assets/site/tokens.css`)

- Loaded **first** on every server-rendered page via `render_head()`
  (`lib/partials.php`), before `diary.css` and any module CSS.
- Introduces a **new `--afg-*` namespace** — it never redefines a module's
  private variables, so adding it is non-breaking. Modules migrate *toward* it.
- Canonical decisions baked in:
  - **One gold:** `--afg-gold` `#f3b416` for fills; `--afg-gold-deep` `#b07e08`
    for *text on light* (WCAG AA). Never set gold text on light with `#f3b416`
    — use `--afg-accent-ink`.
  - **One body font:** Montserrat (`--afg-font-body`). Inter is dropped.
  - **One display font:** Cormorant. **One mono:** JetBrains Mono.
  - 4px **spacing** scale, tokenized **radius**, **shadow**, **motion**, and a
    documented **breakpoint** scale (sm 640 / md 768 / lg 1024 / xl 1280).
  - A zero-specificity global **focus ring** (`:where(...):focus-visible`) so
    every interactive element is keyboard-visible without overriding modules.

## Theming contract

`THEME_BOOT` (in `lib/partials.php`) sets `<html data-theme="light|dark">` at
first paint, defaulting from the OS `prefers-color-scheme` and honoring a
persisted `av.theme`. Tokens resolve in this cascade:

1. `:root` → light defaults.
2. `@media (prefers-color-scheme: dark)` on `:root:not([data-theme="light"])`
   → OS dark for any surface that doesn't run `THEME_BOOT`.
3. `:root[data-theme="dark"]` → explicit dark (wins).
4. `:root[data-theme="light"]` → explicit light (wins over the OS default).

Verified in Chromium: light, OS-dark (no attribute), explicit dark, and explicit
light-on-OS-dark all resolve correctly; `--afg-accent-ink` flips `#b07e08`↔`#f3b416`
so accent text stays AA in both themes.

> **Migration goal:** every module joins this one contract. Modules with their
> own theme switch (e.g. the portal's `av_portal_theme` cookie + `.is-light`)
> should move to `data-theme` so theme is consistent app-wide.

## Migration playbook (per module)

Do this **one module per PR**, so each is independently reviewable and revertible.

1. **Map** the module's private variables to canonical tokens (table below).
2. **Re-point, don't rip out:** redefine each private var in terms of a token,
   keeping the old value as a fallback so output is identical if tokens.css is
   ever missing:
   ```css
   /* before */  --p-muted: #6B7280;
   /* after  */  --p-muted: var(--afg-muted, #6B7280);
   ```
   This is provably non-breaking: same value, now sourced from the shared layer.
3. **Verify:** load the page in light and dark; confirm no visual diff (or an
   intended improvement, e.g. AA-correct accent text).
4. Once a module references only tokens, its private namespace can be deleted.

### Namespace → token map

| Concept | `--afg-*` token | Legacy names to replace |
|---|---|---|
| Brand gold (fill) | `--afg-gold` / `--afg-accent` | `--gold`, `--m-gold`, `--p-accent-2`, `--ch-gold`, `--cc` |
| Gold text (AA) | `--afg-accent-ink` | `--gold-deep`, `--p-accent`, `--m-gold-deep`, `--ch-gold-deep` |
| App background | `--afg-bg` | `--bg`, `--p-bg`, `--color-bg`, `--m-strip` (dark) |
| Card surface | `--afg-surface` | `--surface`, `--p-surface`, `--m-surface`, `--ch-surface` |
| Raised/hover | `--afg-surface-2` | `--surface-2`, `--p-surface-2`, `--ch-surface-2` |
| Border/divider | `--afg-border` | `--p-border`, `--m-divider`, `--ch-line`, `--color-divider` |
| Primary text | `--afg-ink` | `--ink`, `--p-ink`, `--m-ink`, `--ch-ink`, `--auth-ink` |
| Body text | `--afg-body` | `--body`, `--p-body`, `--color-text-body` |
| Muted text | `--afg-muted` | `--muted`, `--p-muted`, `--m-muted`, `--color-text-muted` |
| Success | `--afg-success` / `--afg-success-soft` | `--p-green` / `--p-green-soft`, `--auth-ok` |
| Danger | `--afg-danger` / `--afg-danger-soft` | `--auth-err`, ad-hoc `#dc2626` |
| Focus | `--afg-focus` | `--p-focus`, `--auth-ring` |
| Body font | `--afg-font-body` | `--font-body` (Montserrat), stray `--font`, Inter defs |
| Display font | `--afg-font-display` | `--font-heading`, `--serif`, hardcoded `'Cormorant'` |
| Mono | `--afg-font-mono` | `--font-mono` |
| Spacing | `--afg-space-1..8` | ad-hoc px paddings |
| Radius | `--afg-radius-sm/md/lg/pill` | `--radius-md`, hardcoded radii |
| Shadow | `--afg-shadow-sm/md/lg` | `--card-shadow`, `--p-shadow*`, `--m-shadow` |

## Reference adoption

`portal/portal.css` — the **Membership dues** card (`.dues-*`) already consumes
`--afg-*` tokens (with legacy fallbacks) as the first worked example. Use it as
the template when migrating the rest of the portal, then login, admin, and chioma.

## Rollout status

1. ✅ **Foundation** — `tokens.css` + global wiring + focus ring + dues-card adoption.
2. ✅ **Portal** (`--p-*`) — `--afg-*` mirror its themed palette in the portal scope.
3. ✅ **Static pages** — `tokens.css` injected via `build-chrome.php`.
4. ✅ **Diary base** (`--gold`, `--bg`, `--surface`, `--ink`, `--body`, `--muted`,
   `--divider`, `--link`, `--focus`, fonts) → `var(--afg-*, <literal>)`. This is the
   global base every server-rendered page inherits, so it unifies the whole site.
   Verified in Chromium: **0** resolved-value changes in light or dark.
5. ✅ **Admin / Studio** — now loads `tokens.css`; relies on the (now unified) Diary
   base; stray `--cc` → `var(--afg-accent)`.
6. ✅ **Brand override** — `av_brand_css()` also repoints `--afg-gold/-accent/-accent-ink`,
   so a custom Studio brand colour flows through the token layer.
7. **Login** (`--auth-*`) — already rides the shared `data-theme` + tokens contract via
   `render_head`; optional palette remap remains (values differ slightly, so left literal).
8. **Remaining:** `nav` (`--m-*`) and `chioma` (`--ch-*`) private namespaces — both have
   their own light/dark blocks; migrate the same way when convenient.

Tokens that intentionally stay literal (values differ from `--afg-*`): diary
`--gold-dark`, `--gold-soft` (0.10 vs 0.14), `--card-shadow` (0.14 vs 0.10),
`--radius-md` (12 vs 14), and the `--nav-*`/`--footer-bg` chrome colours.
