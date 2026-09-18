FROM denoland/deno:2.9.7

WORKDIR /opt/infection

COPY devTools/mutator-performance-review/deno.json devTools/mutator-performance-review/deno.lock* devTools/mutator-performance-review/
COPY devTools/mutator-performance-review/src devTools/mutator-performance-review/src
COPY devTools/mutator-performance-review/public devTools/mutator-performance-review/public

# Warms the Deno module/npm cache into this image layer (including esbuild, dynamically imported
# by server.ts's hot-reload watcher). At runtime docker-compose bind-mounts the whole repo over
# /opt/infection (see docker-compose.yml), so this COPY'd source is shadowed by the live host copy
# — only the populated DENO_DIR cache from this step actually survives into the running container.
RUN deno cache --allow-import devTools/mutator-performance-review/src/server.ts

EXPOSE 8000

# The server rebuilds the client bundle itself (via esbuild's watch API — see
# watchAndRebuildClient() in server.ts) against the live mounted source, both once at startup and
# again on every subsequent edit, pushing a reload to any open browser tab over SSE. So a local
# edit takes effect without a container restart, let alone a fresh image. See
# doc/mutator-performance-tooling.md.
CMD ["deno", "run", "--allow-net", "--allow-read", "--allow-write", "--allow-env", "--allow-run", "devTools/mutator-performance-review/src/server.ts"]
