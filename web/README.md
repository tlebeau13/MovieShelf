# web/ — Next.js front

The **visualization dashboard**. Consumes the Symfony REST API — no direct DB access.

## Responsibilities
- Time-series curves (popularity, rank over time).
- "Longest-charting bestsellers", "book rating vs adaptation rating", genre evolution.
- Talks **only** to `api/` over HTTP (JSON). It never touches PostgreSQL directly.

## Scope guard
Keep it lean: data fetching + a charting lib (Recharts / Chart.js). No auth theatre,
no SSR gymnastics — a trends dashboard is mostly client-side fetching.

## Stack
Next.js (App Router), TypeScript, Tailwind, **Recharts**.

Dependencies are managed with **yarn 4** (`packageManager` in `package.json` pins
the version). `.yarnrc.yml` sets `nodeLinker: node-modules` rather than Yarn's
Plug'n'Play default, so the eventual Dockerfile has the same shape as `api/` and
`analytics/`: a cached dependency layer built from `package.json` + `yarn.lock`.

## Charting: why Recharts
The views in #17/#18 need four standard chart types — line (rank over time),
horizontal bar (longest-charting bestsellers), scatter (book rating vs adaptation
rating) and stacked area (genre evolution). Nothing exotic, which rules out the
heavyweights (ECharts, Nivo): their bundle buys capability we never use.

Chosen over Chart.js for three concrete reasons:

- **Theming.** Recharts renders SVG elements that take `className` and CSS
  variables, so light/dark is one palette definition. Chart.js takes colours as
  JS strings at config time, so a theme switch means recomputing config and
  re-rendering.
- **Tooltips are the product.** "Book rating vs adaptation rating" is only
  interesting when hovering a point reveals both titles and both scores. In
  Recharts that is a JSX component styled with Tailwind; in Chart.js it is an
  external HTML callback positioning a floating div by hand.
- **Rank axis.** Rank 1 belongs at the top: `<YAxis reversed />`.

**When to revisit:** Recharts draws SVG, which degrades past a few thousand
nodes. If the scatter ever plots enough adaptations individually to feel sluggish,
either bin the points server-side (it is a MART table, so that belongs in
`analytics/`) or switch that single view to a canvas renderer. The rest of the
views will never approach the limit.

All charts are client components (`'use client'`) — Recharts measures the DOM, so
it cannot render on the server.

## Running

```bash
yarn dev     # http://localhost:3000
yarn build
yarn lint
```
