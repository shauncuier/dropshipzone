# Dropshipzone Admin UI Modernization

## Context

The plugin's 9 admin pages work but look 2018-era: 56 `linear-gradient` uses (animated 5-stop purple hero), glassmorphism tokens, 129 hardcoded hex colors bypassing the existing `--dsz-*` tokens, three duplicate import-card style systems, two parallel progress-bar systems, two badge systems, 27 inline `style=""` attributes in PHP, emoji `✓/✗` mixed with dashicons, raw `.widefat` tables next to bespoke cards, unstyled warning/info toasts (live bug), native `confirm()` dialogs, no dark mode, no reduced-motion support.

User chose: **Clean SaaS look** (flat, calm — Linear/Stripe style), **dark mode**, **styled confirm dialogs**, **reduced-motion support**, **keep confetti**.

## Approach

Rewrite `assets/admin.css` as a coherent design system **keeping every existing `dsz-` class name** (class names are the API; JS hooks must survive). Collapse duplicate systems by styling all legacy class variants with the same unified rules.

### 1. New token layer (`:root`)
- Neutral slate palette (`--dsz-gray-50…900` redefined to modern slate), single indigo accent (`--dsz-primary: #4f46e5`, hover/active/soft variants) — nods to old purple identity without gradients.
- Status: success/warning/error/info + `-soft` background variants.
- NEW: spacing scale `--dsz-space-1…10` (4px base), font tokens (`--dsz-text-xs…2xl`, weights), surface tokens (`--dsz-surface`, `--dsz-surface-2`, `--dsz-border`), focus ring token.
- Subtle shadows (sm/md/lg — low-alpha), radii 6/8/12.
- Dark mode: `@media (prefers-color-scheme: dark)` overrides surface/border/gray/soft tokens only — components stay token-pure so dark "just works".

### 2. admin.css rewrite structure
tokens → base (`.dsz-wrap` typography) → components → page sections → responsive (782px WP + 1100px + 600px only) → dark overrides → `prefers-reduced-motion` (kill all animation/transition).

Component consolidation:
- **Header**: static calm hero — surface background, accent left border or subtle tint; kill `gradientShift` animation.
- **Progress**: one visual system; both class sets (`.dsz-progress-*` and `.dsz-progress-console/*`) styled identically.
- **Badges**: one pill spec; all three legacy sets (`.dsz-badge-*` logs, import variants, `.dsz-import-badge-*`) map to status colors.
- **Import cards**: one card spec covering the v2/v3 class sets.
- **Tables**: new `.dsz-table` component; also style `.dsz-wrap .widefat` to match so auto-import history stops clashing.
- **Buttons**: unify WP core buttons inside `.dsz-wrap` (accent primary, ghost secondary, danger variant `.dsz-btn-danger`); remove ripple.
- **Toasts**: move from JS-injected style into admin.css; add `.dsz-toast-warning` + `.dsz-toast-info` (fixes live bug).
- **NEW confirm modal**: `.dsz-confirm-overlay/-dialog/-title/-text/-actions` with danger variant.
- Keep/refine: cards, form sections, messages, callouts, status pills, empty/loading states, modal, nav (flat tabs, accent underline active state).
- Utility classes to replace PHP inline styles: `.dsz-mt-2/-3`, `.dsz-ml-2`, `.dsz-flex-row`, `.dsz-text-muted`, `.dsz-text-success`, `.dsz-text-error` (last two exist).

### 3. admin.js changes
- `showNotification`: support success/error/warning/info (icon + style per type); delete injected `#dsz-toast-style` block (CSS now owns it).
- `createConfetti`: **keep** (user wants it) but skip when `matchMedia('(prefers-reduced-motion: reduce)').matches`; solid modern colors.
- NEW `DSZAdmin.confirm(message, {danger})` → Promise-based styled modal; replace the 7 native `confirm()` calls (resync-all, auto-map, clear-logs, never-synced, scan-unmapped, etc.). Destructive ops get red confirm button.
- Remove all injected `<style>` blocks (`#dsz-ripple-style`, `#dsz-shake-style`, `#dsz-spin-style`, `#dsz-fadeout-style`, `#dsz-toast-style`) — move keyframes/classes into admin.css. Keep the `.length` guards harmless or delete the injection code entirely (preferred).

### 4. class-admin-ui.php changes
- Replace 27 inline `style=""` with utility classes — EXCEPT dynamic progress-fill widths (lines ~510, ~1003, ~1065 remain inline by necessity).
- `render_auto_import` history table: `✓/✗` emoji → dashicons + status classes; 9 inline styles → classes.
- No structural/markup ID changes.

### 5. Must-not-change JS hooks
All `#dsz-*` IDs and behavioral classes enumerated in audit (e.g. `#dsz-resync-all`, `#dsz-progress-fill`, `.dsz-message`, `.hidden`, `.active`, `.dsz-toast`, `.dsz-spin`, `.dsz-import-item`). Restyle only.

## Files
- `assets/admin.css` — full rewrite (~1,800 lines target, down from 3,776)
- `assets/admin.js` — toast fix, confirm modal, confetti gate, remove style injections
- `includes/class-admin-ui.php` — inline-style cleanup, emoji → dashicons

## Verification
- `php -l` class-admin-ui.php; `node --check` admin.js.
- Grep: no `linear-gradient` outside deliberate spots; no `style="` in PHP except 3 progress fills; no `<style>` injection in JS; every `dsz-` class used in PHP/JS exists in new CSS (scripted cross-check: extract class tokens from PHP/JS, grep against CSS).
- User smoke test on live WP: all 9 pages light+dark, run one sync, one import search, one confirm dialog, toast types.
