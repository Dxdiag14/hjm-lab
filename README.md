# HJM Lab — headless WordPress + Astro

My portfolio ([hjmiranda.com](https://hjmiranda.com)) rebuilt as a static Astro site. WordPress is still the CMS; Astro reads the content through the REST API at build time and outputs plain HTML. No JavaScript is sent to the browser (the Work page filters are CSS-only).

Live: **https://lab.hjmiranda.com**

## How it works

1. **WordPress** – a `Projects` post type (`hjm_project`) with a `Platforms` taxonomy and fields for live URL, tags and kind (`public`, `nda`, `case-study`). Added with a Code Snippet (see `wordpress/headless-snippet.php`).
2. **REST API** – `https://hjmiranda.com/wp-json/wp/v2/projects` returns each project with a ready-made `hjm_image` and `hjm_platforms`.
3. **Astro** – `src/lib/wp.ts` fetches everything once; `src/pages/work/[slug].astro` builds a page per project.
4. **Cloudflare Pages** – hosts the static files. A deploy hook (saved in WordPress › Settings › General) rebuilds the site whenever a project or post is published, updated or deleted.

## Project structure

```
src/
  lib/wp.ts                  WordPress data layer (one fetch, typed)
  data/projects.sample.json  Offline copy of the API response
  data/results.json          WordPress vs Astro numbers (fill in after launch)
  layouts/Base.astro         <head>, header, CTA, footer
  components/ProjectCard.astro
  pages/index.astro          Home
  pages/work/index.astro     All projects + CSS-only filters
  pages/work/[slug].astro    One page per project
  pages/how-its-built.astro  Pipeline, code, results table, trade-offs
  styles/global.css          Same design system as hjmiranda.com
public/fonts/                Geist + Geist Mono (subset WOFF2)
```

## Commands

| Command | What it does |
|---|---|
| `npm install` | Install Astro |
| `npm run dev` | Local dev server at http://localhost:4321 |
| `npm run build` | Build from the live WordPress API into `dist/` |
| `npm run build:offline` | Build from `src/data/projects.sample.json` (no WordPress needed) |
| `npm run preview` | Preview the built site |

Environment variables (optional): `WP_API` (defaults to `https://hjmiranda.com/wp-json/wp/v2`), `WP_OFFLINE=1`.

## Deploy (Cloudflare Pages)

1. Push this folder to a GitHub repo (e.g. `Dxdiag14/hjm-lab`).
2. Cloudflare dashboard › **Workers & Pages › Create › Pages › Connect to Git** › pick the repo.
3. Framework preset **Astro** · Build command `npm run build` · Output directory `dist` · Environment variable `NODE_VERSION` = `22`.
4. **Custom domains › Set up a custom domain** › `lab.hjmiranda.com`. If hjmiranda.com's DNS isn't on Cloudflare, add a CNAME record `lab` → `<project>.pages.dev` at your DNS provider.
5. **Settings › Builds › Deploy hooks › Add** (branch `main`), copy the URL, and paste it into WordPress › **Settings › General › Astro deploy hook URL**.

Now publishing a project in WordPress rebuilds the site in about a minute.

## Adding a project

WordPress › **Projects › Add New**: title, text, excerpt (used on cards), featured image (screenshot), **Platforms**, and under **Custom Fields**: `hjm_url`, `hjm_tags` (comma separated), `hjm_kind`. Set the order with **Page Attributes › Order**.
