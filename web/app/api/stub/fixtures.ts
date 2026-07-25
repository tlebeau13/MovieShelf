/**
 * Fake data standing in for the Symfony API until #16 exposes the MART tables.
 *
 * Deliberately not realistic enough to be mistaken for real output: the titles
 * are placeholders and the numbers are hand-written. The point is only to give
 * the client wrapper and the charts a correctly *shaped* response to render.
 *
 * Delete this directory when #16 lands and point API_BASE_URL at Symfony.
 */

import type { BestsellerLongevity, RankSeries } from "@/lib/api/types";

const WEEKS = [
  "2026-01-04",
  "2026-01-11",
  "2026-01-18",
  "2026-01-25",
  "2026-02-01",
  "2026-02-08",
  "2026-02-15",
  "2026-02-22",
];

function series(bookId: number, title: string, author: string, ranks: number[]): RankSeries {
  return {
    bookId,
    title,
    author,
    // A book can drop off the list and return, so weeks with no rank are simply
    // absent rather than zero — the chart must handle gaps.
    points: ranks.flatMap((rank, index) =>
      rank > 0 ? [{ week: WEEKS[index], rank }] : [],
    ),
  };
}

export const RANK_SERIES: RankSeries[] = [
  series(1, "Placeholder Novel", "A. Author", [3, 2, 1, 1, 2, 4, 6, 9]),
  series(2, "Second Placeholder", "B. Writer", [8, 6, 5, 7, 0, 0, 10, 8]),
  series(3, "Third Placeholder", "C. Scribe", [15, 12, 9, 6, 4, 3, 3, 2]),
];

export const BESTSELLER_LONGEVITY: BestsellerLongevity[] = [
  {
    bookId: 3,
    title: "Third Placeholder",
    author: "C. Scribe",
    weeksOnList: 8,
    firstSeen: "2026-01-04",
    lastSeen: "2026-02-22",
    bestRank: 2,
  },
  {
    bookId: 1,
    title: "Placeholder Novel",
    author: "A. Author",
    weeksOnList: 8,
    firstSeen: "2026-01-04",
    lastSeen: "2026-02-22",
    bestRank: 1,
  },
  {
    bookId: 2,
    title: "Second Placeholder",
    author: "B. Writer",
    weeksOnList: 6,
    firstSeen: "2026-01-04",
    lastSeen: "2026-02-22",
    bestRank: 5,
  },
];
