/**
 * The only way this app reaches data.
 *
 * `web/` talks to `api/` over HTTP and nothing else — no database driver, no
 * direct Postgres dependency anywhere in this codebase (see web/README.md). That
 * rule is the reason this module exists: one wrapper, so there is a single place
 * where the base URL, timeouts, caching and error handling are decided.
 *
 * Native fetch rather than axios on purpose: Next extends fetch with its data
 * cache and revalidation (`next: { revalidate, tags }`). Axios issues its own
 * requests and bypasses all of it, so caching would have to be rebuilt by hand.
 *
 * Intended for Server Components and Route Handlers. Keeping fetches on the
 * server means the API base URL — and any credential it later needs — never
 * reaches the browser, and there is no CORS surface. Client components receive
 * data as props.
 */

import type { BestsellerLongevity, RankSeries } from "./types";

/**
 * Absolute because server-side fetch has no origin to resolve against.
 * Defaults to this app's own stub routes, so #4 runs standalone; when #16 ships
 * the real endpoints, point API_BASE_URL at Symfony and nothing else changes.
 */
const API_BASE_URL = process.env.API_BASE_URL ?? "http://127.0.0.1:3000/api/stub";

/** Trends move slowly — the analytics jobs recompute on their own cron. */
const DEFAULT_REVALIDATE_SECONDS = 3600;

const DEFAULT_TIMEOUT_MS = 10_000;

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly url: string,
    readonly body?: string,
  ) {
    super(message);
    this.name = "ApiError";
  }
}

export interface ApiFetchOptions {
  /** Seconds before Next revalidates the cached response. 0 disables caching. */
  revalidate?: number;
  /** Cache tags, so a future webhook can invalidate precisely. */
  tags?: string[];
  signal?: AbortSignal;
}

export async function apiFetch<T>(path: string, options: ApiFetchOptions = {}): Promise<T> {
  const url = `${API_BASE_URL}${path}`;
  const { revalidate = DEFAULT_REVALIDATE_SECONDS, tags, signal } = options;

  let response: Response;
  try {
    response = await fetch(url, {
      headers: { Accept: "application/json" },
      // A hung API should surface as an error state, not a page that never renders.
      signal: signal ?? AbortSignal.timeout(DEFAULT_TIMEOUT_MS),
      next: { revalidate, tags },
    });
  } catch (cause) {
    // Network-level failure: API down, DNS, timeout. Status 0 distinguishes it
    // from an HTTP error the API actually returned.
    throw new ApiError(
      `Could not reach the API at ${url}: ${(cause as Error).message}`,
      0,
      url,
    );
  }

  if (!response.ok) {
    // Read the body for context, but never let a broken error page mask the
    // original failure.
    const body = await response.text().catch(() => undefined);
    throw new ApiError(
      `API responded ${response.status} ${response.statusText} for ${url}`,
      response.status,
      url,
      body,
    );
  }

  try {
    return (await response.json()) as T;
  } catch (cause) {
    throw new ApiError(
      `API returned invalid JSON for ${url}: ${(cause as Error).message}`,
      response.status,
      url,
    );
  }
}

// ── Endpoints ────────────────────────────────────────────────────────────────
// One function per resource, so components never build URLs themselves and the
// response type is fixed in one place.

/** Rank trajectories over time — the line chart in #17. */
export function getRankSeries(options?: ApiFetchOptions): Promise<RankSeries[]> {
  return apiFetch<RankSeries[]>("/rank-series", options);
}

/** Longest-charting bestsellers — the ranked bar chart in #18. */
export function getBestsellerLongevity(
  options?: ApiFetchOptions,
): Promise<BestsellerLongevity[]> {
  return apiFetch<BestsellerLongevity[]>("/bestseller-longevity", options);
}
