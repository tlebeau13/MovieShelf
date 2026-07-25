#syntax=docker/dockerfile:1

# Pinned deliberately: uv ships breaking changes often enough that :latest makes
# builds non-reproducible.
FROM ghcr.io/astral-sh/uv:0.9-python3.12-bookworm-slim AS base

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    # Copy packages into the venv instead of symlinking to uv's cache, so the
    # runtime stage works without dragging the cache along.
    UV_LINK_MODE=copy \
    # Outside /app on purpose: compose.override.yaml bind-mounts the source over
    # /app for local work, which would otherwise shadow the venv.
    UV_PROJECT_ENVIRONMENT=/opt/venv \
    PATH="/opt/venv/bin:$PATH"

WORKDIR /app

# Non-root, and matching the host UID by default so anything this container ever
# writes to a bind-mount stays owned by the developer rather than root.
ARG UID=1000
ARG GID=1000
RUN groupadd --gid "${GID}" analytics \
    && useradd --uid "${UID}" --gid "${GID}" --create-home analytics


# ── Dependencies ─────────────────────────────────────────────────────────────
# Own stage so that editing source code does not invalidate the (slow) install
# of pandas and friends.
FROM base AS deps

COPY pyproject.toml uv.lock README.md ./
RUN --mount=type=cache,target=/root/.cache/uv \
    uv sync --frozen --no-install-project --no-dev


# ── supercronic ──────────────────────────────────────────────────────────────
# Cron built for containers: crontab syntax, runs unprivileged, logs to stdout,
# and forwards signals so `docker compose stop` is not a 10-second timeout.
# Alpine rather than `base`: this stage only downloads and verifies a static
# binary, and busybox already provides wget + sha1sum. Nothing but the binary is
# copied out.
FROM alpine:3.22 AS supercronic

ARG TARGETARCH
ARG SUPERCRONIC_VERSION=v0.2.48
ARG SUPERCRONIC_SHA1SUM_amd64=016b7c9aebfc8d9fd9526e8ba33b191fc524485f
ARG SUPERCRONIC_SHA1SUM_arm64=2ab9b3bdcf290f60b59700aad876b6e68f3a6b06

RUN set -eux; \
    url="https://github.com/aptible/supercronic/releases/download/${SUPERCRONIC_VERSION}/supercronic-linux-${TARGETARCH}"; \
    case "${TARGETARCH}" in \
      amd64) sha1="${SUPERCRONIC_SHA1SUM_amd64}" ;; \
      arm64) sha1="${SUPERCRONIC_SHA1SUM_arm64}" ;; \
      *) echo "unsupported architecture: ${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    wget -q -O /usr/local/bin/supercronic "$url"; \
    echo "${sha1}  /usr/local/bin/supercronic" | sha1sum -c -; \
    chmod +x /usr/local/bin/supercronic


# ── Dev ──────────────────────────────────────────────────────────────────────
# Deliberately NOT the last stage: compose.yaml builds without a `target:`, which
# resolves to the final stage, and that must be the production one.
FROM base AS dev

COPY --from=supercronic /usr/local/bin/supercronic /usr/local/bin/supercronic
COPY --chown=${UID}:${GID} . .

# Includes the dev group, so pytest and ruff are available in the container.
RUN --mount=type=cache,target=/root/.cache/uv \
    uv sync --frozen \
    && chown -R "${UID}:${GID}" /opt/venv

USER analytics

CMD ["supercronic", "-passthrough-logs", "/app/crontab"]


# ── Runtime ──────────────────────────────────────────────────────────────────
FROM base AS runtime

COPY --from=supercronic /usr/local/bin/supercronic /usr/local/bin/supercronic
COPY --from=deps --chown=${UID}:${GID} /opt/venv /opt/venv
# README.md comes along because pyproject.toml declares it as the project readme.
COPY --chown=${UID}:${GID} pyproject.toml uv.lock crontab README.md ./
COPY --chown=${UID}:${GID} src/ ./src/

# Installs the project itself; its dependencies are already in the venv from the
# `deps` stage, so this resolves to a fast local build.
RUN --mount=type=cache,target=/root/.cache/uv \
    uv sync --frozen --no-dev \
    && chown -R "${UID}:${GID}" /opt/venv

USER analytics

# No HEALTHCHECK: this container is a scheduler, not a server. Liveness is
# "supercronic is running", which Docker's restart policy already covers.
CMD ["supercronic", "-passthrough-logs", "/app/crontab"]
