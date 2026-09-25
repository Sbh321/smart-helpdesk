# syntax=docker/dockerfile:1.7
# Edge proxy image: Caddy serving the built SPA and routing the platform hosts (ADR-0021).
ARG NODE_IMAGE=node:24-alpine
ARG CADDY_IMAGE=caddy:2.11-alpine

FROM ${NODE_IMAGE} AS build
RUN corepack enable
WORKDIR /app
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
RUN --mount=type=cache,id=pnpm,target=/root/.local/share/pnpm/store pnpm install --frozen-lockfile --ignore-scripts
COPY . .
RUN pnpm exec vite build && pnpm build:landing

# The platform documentation (docs/ as a VitePress site, M5-05). docs/ is outside this build context, so it
# comes in as the named context `docs` (compose additional_contexts, CI build-contexts).
FROM ${NODE_IMAGE} AS platform-docs
RUN corepack enable
WORKDIR /docs
COPY --from=docs package.json pnpm-lock.yaml pnpm-workspace.yaml ./
RUN --mount=type=cache,id=pnpm,target=/root/.local/share/pnpm/store pnpm install --frozen-lockfile
COPY --from=docs . .
RUN pnpm build

FROM ${CADDY_IMAGE} AS runtime
RUN apk add --no-cache gettext
COPY --from=build /app/dist /srv/app
COPY --from=build /app/dist-landing /srv/landing
COPY --from=platform-docs /docs/.vitepress/dist /srv/platform-docs
COPY docker/config.template.json /etc/shp/config.template.json
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/Caddyfile.single /etc/caddy/Caddyfile.single
COPY docker/entrypoint.sh /usr/local/bin/shp-entrypoint
RUN chmod +x /usr/local/bin/shp-entrypoint
ENTRYPOINT ["shp-entrypoint"]
CMD ["caddy", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]
