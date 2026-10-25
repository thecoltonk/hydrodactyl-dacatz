# Hydrodactyl — Da Catz Hosting build

Hydrodactyl v6.4.1 plus:

- **Players tab** (Minecraft Java): live player list, kick/ban/OP/whitelist, gamemode, teleport
  (to current position, last death, respawn point, coordinates or another player), stats,
  saved inventory + ender chest viewer and editor, offline-player list, offline-mode whitelist
  fix and skin uploads (SkinRestorer / SkinsRestorer). Ported in part from
  [Aquadactyl/MCPlayersJava](https://github.com/Aquadactyl/MCPlayersJava) (BSD-2, see `licenses/`).
- **Modpacks tab**: one-click Modrinth and CurseForge modpack installs (fresh install).
- **Plugins & Mods**: CurseForge source, private per-user collections, quick install and
  "install all".
- **Server plans**: admins give a user total RAM / CPU / storage / server count (Admin → Users →
  user → Server Plan). That user can then create, resize and delete their own servers from the
  dashboard within those totals. Admins also get a pencil on every server to resize it freely.
- **Da Catz branding**: login screen and optional background art.
- **Discord bot** (Admin → Settings → Discord): staff feed channel for server lifecycle,
  failures and security events (no pings, no IPs/emails; admin logins are not posted), and
  account linking (Account → Discord) so users get DMs about their own servers and account.
  REST only, so nothing extra to run; the token and OAuth secret are stored encrypted.
- **No email**: mail is logged unless `MAIL_ENABLED=true`; admins set passwords.

CurseForge needs `CURSEFORGE_API_KEY` in the panel's compose environment (write every `$` as `$$`).

## Deploying

On the VPS:

```
curl -fsSL https://raw.githubusercontent.com/thecoltonk/hydrodactyl-dacatz/dacatz/dacatz/update-hydro.sh | bash
```

Optional background art: put `/root/dacatz-ground.png` (login) and `/root/dacatz-aerial.png`
(panel) on the VPS before running; they are kept in `/opt/hydrodactyl/branding`.

## Updating to a new Hydrodactyl release

```
git fetch https://github.com/BlueprintFramework/hydrodactyl.git --tags
git rebase --onto <new-tag> v6.4.1 dacatz
```
