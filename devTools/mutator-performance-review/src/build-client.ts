/**
 * One-shot production build of public/app.js, used by `make mutator-performance-review` and by
 * `deno task build:client`. Goes through esbuild's JS API (not its CLI binary) with the Deno
 * loader plugin, because app.ts imports "chart.js" via deno.json's npm import map — the plain
 * esbuild CLI has no import map and can't resolve that specifier on its own. server.ts's dev-mode
 * watcher needs the same plugin for the same reason (see watchAndRebuildClient()).
 */
import * as esbuild from "esbuild";
import { denoPlugins } from "@luca/esbuild-deno-loader";

const result = await esbuild.build({
  entryPoints: [new URL("./client/app.ts", import.meta.url).pathname],
  outfile: new URL("../public/app.js", import.meta.url).pathname,
  bundle: true,
  format: "esm",
  // esbuild-deno-loader vendors its own copy of esbuild's Plugin/PluginBuild types (from whatever
  // esbuild version it was published against), structurally close to but not identical to the
  // "esbuild" import's own — hence the cast rather than a real behavioural mismatch.
  plugins: denoPlugins() as esbuild.Plugin[],
});
esbuild.stop();

if (result.errors.length > 0) Deno.exit(1);
