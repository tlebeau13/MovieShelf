import { fileURLToPath } from "node:url";

import { defineConfig } from "vitest/config";

export default defineConfig({
  // Mirrors the `@/*` paths entry in tsconfig.json; Vite does not read it.
  resolve: {
    alias: { "@": fileURLToPath(new URL(".", import.meta.url)) },
  },
  test: {
    // Node rather than jsdom: what is worth testing here is the server-side
    // fetch wrapper. The charts are Recharts' code and need a real browser to
    // say anything useful about.
    environment: "node",
    include: ["**/*.test.ts"],
    exclude: ["node_modules/**", ".next/**"],
  },
});
