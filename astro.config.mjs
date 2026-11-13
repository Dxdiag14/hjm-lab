// @ts-check
import { defineConfig } from "astro/config";

// Static site: every page is built once, at deploy time, from WordPress content.
export default defineConfig({
  site: "https://lab.hjmiranda.com",
  trailingSlash: "always",
  build: {
    inlineStylesheets: "always" // one small CSS file → inline it, no render-blocking request
  }
});
