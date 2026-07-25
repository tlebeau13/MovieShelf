import { NextResponse } from "next/server";

import { BESTSELLER_LONGEVITY } from "../fixtures";

/** Stub for the #18 longest-charting endpoint. Replaced by Symfony in #16. */
export async function GET() {
  return NextResponse.json(BESTSELLER_LONGEVITY);
}
