import { NextResponse } from "next/server";

import { RANK_SERIES } from "../fixtures";

/** Stub for the #17 rank-over-time endpoint. Replaced by Symfony in #16. */
export async function GET() {
  return NextResponse.json(RANK_SERIES);
}
