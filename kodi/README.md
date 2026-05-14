# WikiFlix — Kodi addon

A thin Kodi 19+ (Matrix and later) addon that browses the WikiFlix catalogue
via the public `api.php` endpoint and hands playable URLs to Kodi's built-in
player.

## Layout

```
plugin.video.wikiflix/
├── addon.xml                 — manifest
├── default.py                — routing + view dispatch
├── lib/
│   ├── api.py                — JSON client for api.php
│   └── playback.py           — source-property → playable URL
└── resources/
    ├── icon.png              — 256×256 (or 512×512) addon icon
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
