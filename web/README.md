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
Next.js (App Router), TypeScript.
