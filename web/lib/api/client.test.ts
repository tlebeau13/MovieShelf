import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const BASE = "https://api.test/v1";

/**
 * client.ts resolves API_BASE_URL once, at import time, so each test needs a
 * fresh module instance rather than a shared one.
 */
async function loadClient() {
  vi.resetModules();
  return import("./client");
}

let fetchMock: ReturnType<typeof vi.fn>;

beforeEach(() => {
  vi.stubEnv("API_BASE_URL", BASE);
  fetchMock = vi.fn();
  vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

function json(body: unknown, init?: ResponseInit) {
  return new Response(JSON.stringify(body), init);
}

describe("apiFetch", () => {
  it("resolves the path against API_BASE_URL and decodes the body", async () => {
    const { apiFetch } = await loadClient();
    fetchMock.mockResolvedValue(json([{ bookId: 1 }]));

    await expect(apiFetch("/rank-series")).resolves.toEqual([{ bookId: 1 }]);
    expect(fetchMock).toHaveBeenCalledWith(
      `${BASE}/rank-series`,
      expect.objectContaining({ headers: { Accept: "application/json" } }),
    );
  });

  it("forwards revalidate and tags to Next's fetch extension", async () => {
    const { apiFetch } = await loadClient();
    fetchMock.mockResolvedValue(json([]));

    await apiFetch("/rank-series", { revalidate: 0, tags: ["rank"] });

    expect(fetchMock.mock.calls[0][1]).toMatchObject({
      next: { revalidate: 0, tags: ["rank"] },
    });
  });

  it("defaults to a one-hour revalidate window", async () => {
    const { apiFetch } = await loadClient();
    fetchMock.mockResolvedValue(json([]));

    await apiFetch("/rank-series");

    expect(fetchMock.mock.calls[0][1]).toMatchObject({ next: { revalidate: 3600 } });
  });

  it("reports an unreachable API as status 0", async () => {
    const { apiFetch, ApiError } = await loadClient();
    fetchMock.mockRejectedValue(new Error("ECONNREFUSED"));

    // Status 0 is what lets a caller tell "API down" from "API said no".
    const error = await apiFetch("/rank-series").catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(ApiError);
    expect(error).toMatchObject({ status: 0, url: `${BASE}/rank-series` });
  });

  it("captures status and body on an HTTP error", async () => {
    const { apiFetch } = await loadClient();
    fetchMock.mockResolvedValue(
      new Response("mart.rank_series is empty", { status: 503, statusText: "Service Unavailable" }),
    );

    await expect(apiFetch("/rank-series")).rejects.toMatchObject({
      name: "ApiError",
      status: 503,
      body: "mart.rank_series is empty",
    });
  });

  it("treats an unparseable body as an error, not as data", async () => {
    const { apiFetch } = await loadClient();
    fetchMock.mockResolvedValue(new Response("<html>proxy error</html>", { status: 200 }));

    await expect(apiFetch("/rank-series")).rejects.toMatchObject({
      name: "ApiError",
      status: 200,
    });
  });
});

describe("endpoints", () => {
  it.each([
    ["getRankSeries", "/rank-series"],
    ["getBestsellerLongevity", "/bestseller-longevity"],
  ] as const)("%s requests %s", async (name, path) => {
    const client = await loadClient();
    fetchMock.mockResolvedValue(json([]));

    await client[name]();

    expect(fetchMock.mock.calls[0][0]).toBe(`${BASE}${path}`);
  });
});
