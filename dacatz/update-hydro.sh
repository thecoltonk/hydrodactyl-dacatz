#!/usr/bin/env bash
# Rebuilds the Da Catz Hydrodactyl panel from github.com/thecoltonk/hydrodactyl-dacatz
# (branch "dacatz") and restarts it.
#   curl -fsSL https://raw.githubusercontent.com/thecoltonk/hydrodactyl-dacatz/dacatz/dacatz/update-hydro.sh | bash
# Rollback: cd /opt/hydrodactyl && cp docker-compose.yml.pre-players docker-compose.yml && docker compose up -d panel
set -euo pipefail

REPO=https://github.com/thecoltonk/hydrodactyl-dacatz.git
BRANCH=${BRANCH:-dacatz}
SRC=/opt/hydrodactyl-src
TAG=hydrodactyl-players:v6.4.1
COMPOSE_DIR=/opt/hydrodactyl

command -v git >/dev/null || { apt-get update -qq && apt-get install -y -qq git; }

echo "==> Fetching $BRANCH"
if [ -d "$SRC/.git" ]; then
  git -C "$SRC" remote set-url origin "$REPO"
  git -C "$SRC" fetch -q --depth 1 origin "$BRANCH"
  git -C "$SRC" reset -q --hard FETCH_HEAD
  git -C "$SRC" clean -qfd
else
  git clone -q --depth 1 --branch "$BRANCH" "$REPO" "$SRC"
fi
echo "    $(git -C "$SRC" log -1 --format='%h %s')"

echo "==> Branding images"
# Optional: put /root/dacatz-ground.png (login) and /root/dacatz-aerial.png (panel) here
# before running. They're kept in $BRAND so later updates reuse them.
BRAND=/opt/hydrodactyl/branding
mkdir -p "$BRAND"
for name in ground aerial; do
  if [ -f "/root/dacatz-$name.png" ]; then cp "/root/dacatz-$name.png" "$BRAND/$name.png"; fi
done
if ls "$BRAND"/*.png >/dev/null 2>&1; then
  command -v cwebp >/dev/null || { apt-get update -qq && apt-get install -y -qq webp; }
  mkdir -p "$SRC/public/dacatz"
  for name in ground aerial; do
    if [ -f "$BRAND/$name.png" ]; then
      width=$(file -b "$BRAND/$name.png" 2>/dev/null | grep -oE '[0-9]+ x [0-9]+' | head -1 | cut -d' ' -f1 || true)
      resize=""
      if [ -n "$width" ] && [ "$width" -gt 2560 ]; then resize="-resize 2560 0"; fi
      cwebp -quiet -q 80 $resize "$BRAND/$name.png" -o "$SRC/public/dacatz/$name.webp"
      echo "    $name.webp ready"
    fi
  done
else
  echo "    none found, skipping (login/panel keep the plain background)"
fi

echo "==> Building image"
docker build -t "$TAG" "$SRC"

echo "==> Restarting panel"
cd "$COMPOSE_DIR"
[ -f docker-compose.yml.pre-players ] || cp docker-compose.yml docker-compose.yml.pre-players
sed -i -E "s#^(\s*image:\s*)ghcr.io/blueprintframework/hydrodactyl:.*#\1$TAG#" docker-compose.yml
grep -q "$TAG" docker-compose.yml || { echo "Could not find the panel image line in docker-compose.yml"; exit 1; }
docker compose up -d panel

echo
echo "Done. Hard-refresh the panel."
