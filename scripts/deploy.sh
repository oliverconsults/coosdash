#!/usr/bin/env bash
set -euo pipefail

PROJECT="coosdash"
REPO_DIR="/home/deploy/projects/coos"
RUNTIME_DIR="/var/www/${PROJECT}"
KEEP_RELEASES="${KEEP_RELEASES:-10}"

REL_ID="$(date +%Y%m%d_%H%M%S)"
RELEASE_DIR="${RUNTIME_DIR}/releases/${REL_ID}"

echo "Deploying ${PROJECT} release=${REL_ID}"

mkdir -p "${RUNTIME_DIR}/releases" "${RUNTIME_DIR}/shared"
mkdir -p "${RUNTIME_DIR}/shared/data" "${RUNTIME_DIR}/shared/logs" "${RUNTIME_DIR}/shared/tmp" "${RUNTIME_DIR}/shared/uploads"

mkdir -p "${RELEASE_DIR}"

# Copy code
rsync -a --exclude '.git' "${REPO_DIR}/" "${RELEASE_DIR}/"

# Link shared dirs into release
for d in data logs tmp uploads; do
  rm -rf "${RELEASE_DIR}/${d}" 2>/dev/null || true
  ln -sfn "${RUNTIME_DIR}/shared/${d}" "${RELEASE_DIR}/${d}"
done

# Switch current
ln -sfn "${RELEASE_DIR}" "${RUNTIME_DIR}/current"

echo "Switched current -> ${RELEASE_DIR}"

# Cleanup old releases
if [[ "${KEEP_RELEASES}" != "0" ]]; then
  echo "Cleanup: keeping last ${KEEP_RELEASES} releases"
  CURRENT_REAL="$(readlink -f "${RUNTIME_DIR}/current" || true)"
  mapfile -t SORTED < <(ls -1dt "${RUNTIME_DIR}/releases"/* 2>/dev/null || true)
  keep_left=$((KEEP_RELEASES-1))
  for r in "${SORTED[@]}"; do
    [[ -z "$r" ]] && continue
    if [[ -n "$CURRENT_REAL" && "$(readlink -f "$r")" == "$CURRENT_REAL" ]]; then
      continue
    fi
    if (( keep_left > 0 )); then
      keep_left=$((keep_left-1))
      continue
    fi
    echo "  removing old release: $r"
    rm -rf --one-file-system "$r"
  done
fi

# Reload php-fpm (opcache)
if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet php8.3-fpm; then
  echo "Reloading php8.3-fpm..."
  if sudo -n systemctl reload php8.3-fpm; then
    echo "php8.3-fpm reload: OK (sudo -n)"
  else
    echo "php8.3-fpm reload: FAILED" >&2
    exit 1
  fi
fi

echo "Done."
