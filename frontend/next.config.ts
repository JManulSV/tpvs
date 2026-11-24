import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  /* config options here */
  // Production image runs the self-contained .next/standalone/server.js (SPEC 26).
  output: "standalone",
  cacheComponents: true,
  partialPrefetching: true,
  allowedDevOrigins: (process.env.NEXT_ALLOWED_DEV_ORIGINS ?? "")
    .split(",")
    .map((origin) => origin.trim())
    .filter(Boolean),
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
};

export default nextConfig;
