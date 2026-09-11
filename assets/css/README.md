# Compiled Tailwind CSS (replaces the Play CDN)

ISO 25010 Security/Performance audit (2026-09-06): every page previously
loaded `https://cdn.tailwindcss.com` at runtime, which Tailwind's own docs
say not to use in production (ships the full JIT compiler, recompiles on
every page load, no purge/caching). Replaced with real compiled, minified,
purged CSS files built by the Tailwind CLI (`tailwindcss@3.4.16`, matching
the CDN version that was in use).

## Why there are 52 CSS files instead of one

Different pages (and even different pages *within the same college*) define
different inline `tailwind.config = {...}` objects - a dean's dashboard uses
a different `primary`/`gold` color than that same college's own eval page,
and every college uses different colors from every other college. A single
shared compiled stylesheet cannot serve `bg-primary` as green on one page
and navy on another, so each distinct config got its own build, scoped via
Tailwind's `content` option to only the file(s) that actually use it -
functionally identical to what the Play CDN was doing per-page, just
precompiled instead of recompiled on every request.

`tw_groups.json` is the manifest: for each group id, the exact
`tailwind.config` object text that was extracted from the page(s), and
which file(s) share it. `tw-0.css` is the plain-default build for the two
pages that loaded the CDN with no custom config at all
(`view_evaluation.php`, `save_idp_forms.php`).

## Rebuilding after you edit a page

If you change a page's colors/theme (edit its inline config comment... wait,
there is no inline config anymore - see below) or add new Tailwind utility
classes to a page's markup, the compiled CSS needs to be regenerated so the
new classes actually get generated (Tailwind's JIT only emits CSS for
classes it can find in the `content` files).

1. From the project root: `node assets/css/build-tailwind.js`
   - Regenerates every `tw.config.<id>.js` from `tw_groups.json` and
     rebuilds every `tw-<id>.css`.
2. If you added a *new* page or changed which colors a page's theme needs,
   the config text lives in `tw_groups.json` - edit the relevant group's
   `configText` (or `files` list) by hand, then rerun the build script.
   There's no more inline `<script>tailwind.config = {...}</script>` in the
   PHP files themselves - each page just has:
   ```html
   <link rel="stylesheet" href="assets/css/tw-<id>.css">
   ```
   (or `../assets/css/tw-<id>.css` from inside a `*_admin/` folder).

## Setup

`npm install` in the project root (installs `tailwindcss@3.4.16` as a
devDependency, per `package.json`). `node_modules/` is gitignored.
