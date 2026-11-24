# Targets:
#   deps   node_modules installed from package-lock.json.
#   dev    Development: code is bind-mounted, node_modules is synced by the entrypoint.
#   build  (SPEC 26 step 12)
#   prod   (SPEC 26 step 12)

FROM node:22-alpine AS deps

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci \
    && sha256sum package-lock.json | cut -d ' ' -f 1 > node_modules/.package-lock.sha256


FROM node:22-alpine AS dev

# Remap the image's node user (UID 1000) to the host user so files written to
# the bind mount and the volumes belong to that user.
ARG UID=1000
ARG GID=1000

RUN apk add --no-cache shadow \
    && groupmod --non-unique --gid "${GID}" node \
    && usermod --non-unique --uid "${UID}" --gid "${GID}" node \
    && apk del shadow \
    && mkdir -p /app/.next \
    && chown node:node /app /app/.next

WORKDIR /app

# A new, empty node_modules volume is seeded from here, owned by node.
COPY --from=deps --chown=node:node /app/node_modules ./node_modules

COPY --chmod=755 docker/entrypoint-dev.sh /usr/local/bin/tpvs-entrypoint

USER node

ENTRYPOINT ["tpvs-entrypoint"]

EXPOSE 3000

CMD ["npm", "run", "dev", "--", "--hostname", "0.0.0.0"]
