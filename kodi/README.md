# WikiFlix — Kodi addon

A thin Kodi 19+ (Matrix and later) addon that browses the WikiFlix catalogue
via the public `api.php` endpoint and hands playable URLs to Kodi's built-in
player.

Licensed under **GPL-3.0-or-later** (see `LICENSE` at the repo root).

## Layout

```
plugin.video.wikiflix/
├── addon.xml                 — manifest
├── default.py                — routing + view dispatch
├── lib/
│   ├── api.py                — JSON client for api.php
│   └── playback.py           — source-property → playable URL
└── resources/
    ├── icon.png              — 512×512 addon icon
    ├── fanart.jpg            — optional 1280×720 background art
    ├── settings.xml          — language preference
    └── language/resource.language.en_gb/strings.po
```

The addon talks to `https://wikiflix.toolforge.org/api.php` only; no
Toolforge or shared-library code is imported.

## Installing locally

1. Zip the addon directory:

   ```sh
   cd kodi
   zip -r plugin.video.wikiflix-0.1.0.zip plugin.video.wikiflix
   ```

2. In Kodi: **Settings → Add-ons → Install from zip file** → pick the zip.
   You may need to enable "Unknown sources" first.

3. Launch from **Videos → Add-ons → WikiFlix**.

## Configuration

The only user-facing setting is **Preferred label language** — an ISO 639-1
code (`en`, `de`, `fr`, …) forwarded to the API so section/genre labels are
returned in that language where available. Defaults to `en`.

## Playback support

| Source                | Wikidata property        | How it plays                                          |
|-----------------------|--------------------------|-------------------------------------------------------|
| Wikimedia Commons     | P10 (video)              | Direct stream via `Special:FilePath`                  |
| Internet Archive      | P724                     | Delegated to `plugin.video.archive.org` if installed  |
| YouTube               | P1651                    | Delegated to `plugin.video.youtube` if installed      |
| Vimeo                 | P4015                    | Delegated to `plugin.video.vimeo` if installed        |
| DailyMotion           | P11731                   | Delegated to `plugin.video.dailymotion_com` if installed |

When an entry has multiple sources we prefer direct Commons playback so the
addon works out of the box without third-party dependencies. Trailers are
skipped in favour of the main feature.

## Submitting to the official Kodi addon repository

The single official repository — shipped with every Kodi install — lives at
**[xbmc/repo-plugins](https://github.com/xbmc/repo-plugins)** on GitHub.
Submission is a pull request against the branch for the Kodi release you
want to target (currently `omega` for Kodi 21, `piers` for the in-development
Kodi 22). Rules and review guidelines:
**<https://kodi.wiki/view/Add-on_rules>**.

### Pre-submission checklist

- [ ] **`addon.xml`** has correct `id`, `name`, `version` (semver), `provider-name`, and a non-empty `description` and `summary` in at least `en_GB`.
- [ ] **`<license>`** field matches reality (`GPL-3.0-or-later`) and the repo has a `LICENSE` file with the full text.
- [ ] **`<source>`** points at the public Git repo, **`<website>`** at the user-facing site.
- [ ] **Icon** present at `resources/icon.png`, square, 256×256 or 512×512.
- [ ] **Fanart** at `resources/fanart.jpg` (1280×720 or 1920×1080) — *optional* but recommended; remove the `<fanart>` line from `addon.xml` if not provided.
- [ ] **Python version pinned** via `<import addon="xbmc.python" version="3.0.0"/>` (Kodi 19+).
- [ ] **No bundled third-party Python libraries** — depend on Kodi's stdlib or declare `script.module.*` imports explicitly.
- [ ] **`news` element** with a brief changelog inside `<extension point="xbmc.addon.metadata">` (Kodi shows this in the "What's new" dialog).
- [ ] **Assets paths** in `<assets>` resolve from the addon root (`resources/icon.png`, not `/resources/icon.png`).
- [ ] **No network calls at import time** — defer everything to `main()`. The repo's automated checks reject addons that hit the network during a dry-import.
- [ ] **No `print(...)`** in production code — use `xbmc.log(msg, xbmc.LOGINFO)` instead.
- [ ] **No hard-coded user paths**, secrets, or analytics IDs.
- [ ] **Unique addon id** — verify nothing called `plugin.video.wikiflix` already exists on the target branch:
      `git ls-files | grep plugin.video.wikiflix` in a clone of `xbmc/repo-plugins`.
- [ ] **Strings stay within ID range 30000–30999** for addon-defined strings; do not redefine Kodi core strings.

### Submission flow

1. Fork `xbmc/repo-plugins` on GitHub and clone your fork.
2. Check out the branch for your target Kodi version (e.g. `git checkout omega`).
3. Copy the `plugin.video.wikiflix/` directory into the repo root (one addon per top-level directory — that's the repo's whole structure).
4. Commit, push, open a PR against the same branch on the upstream repo.
5. A bot runs static checks (manifest validity, import dry-run, asset presence). Fix anything it flags.
6. A human reviewer then signs off. Typical turnaround is a few days to a couple of weeks depending on queue.
7. Once merged, the addon appears under **Add-ons → Install from repository → Kodi Add-on repository → Video add-ons** for every Kodi user on that release.

### Updates

Bump the `version` attribute in `addon.xml`, add a `news` entry, and open a
new PR with the changed files. Same review process, usually faster.
