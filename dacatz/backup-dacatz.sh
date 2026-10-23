#!/usr/bin/env bash
# Full backup of the Da Catz Hosting VPS: panel database, panel + Wings config,
# certs, SSH/firewall config, and every game server's files.
# Output: /root/backups/dacatz-<date>-config.tar.gz  (small, has DB + secrets)
#         /root/backups/dacatz-<date>-servers.tar.gz (game server files)
set -euo pipefail

STAMP=$(date +%Y%m%d-%H%M)
DEST=/root/backups
WORK="$DEST/dacatz-$STAMP"
COMPOSE=/opt/hydrodactyl/docker-compose.yml
# Where Wings/Elytra keeps server files (system.data in its config).
VOLUMES=$(awk '/^[[:space:]]+data:/{print $2; exit}' /etc/pterodactyl/config.yml 2>/dev/null)
VOLUMES=${VOLUMES:-/var/lib/pterodactyl/volumes}
umask 077
mkdir -p "$WORK"

echo "==> Checking disk space"
need=$(du -sb "$VOLUMES" 2>/dev/null | cut -f1 || echo 0)
free=$(df -B1 --output=avail "$DEST" | tail -1 | tr -d ' ')
echo "    game servers: $((need / 1024 / 1024)) MiB, free: $((free / 1024 / 1024)) MiB"
if [ "$need" -gt "$free" ]; then
  echo "Not enough free space for the servers archive. Free some space or skip it with: SKIP_SERVERS=1 bash $0"
  [ "${SKIP_SERVERS:-0}" = 1 ] || exit 1
fi

echo "==> Dumping panel database"
docker compose -f "$COMPOSE" exec -T database sh -c \
  'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --all-databases --single-transaction --routines --events --triggers' \
  | gzip -6 > "$WORK/panel-db.sql.gz"
gzip -t "$WORK/panel-db.sql.gz"
echo "    $(du -h "$WORK/panel-db.sql.gz" | cut -f1)"

echo "==> Recording versions and state"
{
  echo "date: $(date -Is)"
  echo "host: $(hostname) $(uname -r)"
  docker compose -f "$COMPOSE" images 2>/dev/null || true
  wings --version 2>/dev/null || true
  ufw status verbose 2>/dev/null || true
  crontab -l 2>/dev/null || true
} > "$WORK/info.txt"

echo "==> Archiving config (panel, Wings, certs, SSH, firewall)"
paths=()
for p in \
  /opt/hydrodactyl/docker-compose.yml /opt/hydrodactyl/docker-compose.yml.pre-players /opt/hydrodactyl/branding \
  /srv/pterodactyl/var /srv/pterodactyl/nginx /srv/pterodactyl/certs /srv/pterodactyl/storage \
  /etc/pterodactyl /etc/systemd/system/wings.service /etc/letsencrypt \
  /etc/ssh/sshd_config /etc/ssh/sshd_config.d /root/.ssh/authorized_keys \
  /etc/ufw /etc/fail2ban /etc/cron.d /var/spool/cron/crontabs \
  /root/update-hydro.sh /root/dacatz-ground.png /root/dacatz-aerial.png; do
  if [ -e "$p" ]; then paths+=("$p"); fi
done
tar -czf "$WORK/config.tar.gz" -C / "${paths[@]#/}"

tar -czf "$DEST/dacatz-$STAMP-config.tar.gz" -C "$DEST" "dacatz-$STAMP"
rm -rf "$WORK"

if [ "${SKIP_SERVERS:-0}" != 1 ] && [ -d "$VOLUMES" ]; then
  echo "==> Archiving game servers (this can take a while)"
  echo "    Running servers are copied live; run save-all in their consoles first for the cleanest copy."
  set +e
  tar -czf "$DEST/dacatz-$STAMP-servers.tar.gz" --warning=no-file-changed -C "$(dirname "$VOLUMES")" "$(basename "$VOLUMES")"
  status=$?
  set -e
  # 1 = some files changed while being read (live servers); the archive is still good.
  [ "$status" -le 1 ] || { echo "tar failed ($status)"; exit "$status"; }
fi

cd "$DEST"
sha256sum dacatz-"$STAMP"-*.tar.gz > "dacatz-$STAMP.sha256"
echo
ls -lh dacatz-"$STAMP"*
echo
echo "Done. These contain passwords and keys (APP_KEY, DB, Wings token, CurseForge key): keep them private."
echo "On your PC (PowerShell):"
echo "  scp \"root@169.58.139.95:$DEST/dacatz-$STAMP*\" \"\$HOME\\Downloads\""
echo "Then free the space here: rm $DEST/dacatz-$STAMP*"
