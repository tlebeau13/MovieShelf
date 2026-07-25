/**
 * Shapes returned by the Symfony REST API, which serves the MART tables.
 *
 * PROVISIONAL: the real contract is defined when #16 exposes the mart schema
 * over HTTP. These mirror the tables described in db/README.md closely enough to
 * build against, and the stub routes under app/api/stub/ return exactly this.
 * Expect to reconcile them with the actual API response in #16.
 */

/** One book's position on the bestseller list for a given week. */
export interface RankPoint {
  /** ISO date of the list, e.g. "2026-03-15". */
  week: string;
  /** 1 is the top of the list. Charts should render this axis reversed. */
  rank: number;
}

/** A single book's trajectory over time — one line on the rank chart. */
export interface RankSeries {
  bookId: number;
  title: string;
  author: string;
  points: RankPoint[];
}

/** mart.bestseller_longevity — how long a book stayed on the list. */
export interface BestsellerLongevity {
  bookId: number;
  title: string;
  author: string;
  weeksOnList: number;
  firstSeen: string;
  lastSeen: string;
  bestRank: number;
}
