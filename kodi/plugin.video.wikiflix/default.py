"""WikiFlix Kodi addon — entry point.

Routes (encoded in the plugin URL via the `view` query parameter):

    /                          → root menu
    ?view=special&key=…        → "Recently added" / "Highly ranked" / "Popular"
    ?view=sections             → browseable list of catalogue sections
    ?view=section&q=…&prop=…   → entries within one section
    ?view=search               → keyboard prompt, then results
    ?view=play&q=…             → resolve & hand the URL to the player
"""

from __future__ import annotations

import sys
import urllib.parse
from typing import Any, Dict, Iterable, Optional

import xbmc
import xbmcaddon
import xbmcgui
import xbmcplugin

from lib.api import ApiError, WikiStreamApi
from lib.playback import commons_thumb_url, pick_best_file, resolve_play_url


ADDON = xbmcaddon.Addon()
ADDON_ID = ADDON.getAddonInfo("id")
HANDLE = int(sys.argv[1])
BASE = sys.argv[0]  # e.g. "plugin://plugin.video.wikiflix/"

BACKEND_URL = "https://wikiflix.toolforge.org"
PAGE_SIZE = 50


# ---------- helpers ----------

def _t(string_id: int) -> str:
    return ADDON.getLocalizedString(string_id)


def _api() -> WikiStreamApi:
    language = ADDON.getSetting("language") or xbmc.getLanguage(xbmc.ISO_639_1) or "en"
    return WikiStreamApi(BACKEND_URL, language)


def _url(**params: Any) -> str:
    return BASE + "?" + urllib.parse.urlencode(
        {k: v for k, v in params.items() if v is not None}
    )


def _notify(msg: str, heading: Optional[str] = None) -> None:
    xbmcgui.Dialog().notification(
        heading or ADDON.getAddonInfo("name"),
        msg,
        xbmcgui.NOTIFICATION_INFO,
        4000,
    )


def _make_entry_listitem(entry: Dict[str, Any]) -> xbmcgui.ListItem:
    label = entry.get("label") or f"Q{entry.get('q')}"
    year = entry.get("year")
    if year:
        label = f"{label} ({year})"

    li = xbmcgui.ListItem(label=label)

    image = entry.get("image")
    if image:
        thumb = commons_thumb_url(image, 400)
        li.setArt({"thumb": thumb, "poster": thumb, "icon": thumb})

    minutes = entry.get("minutes")
    info = {
        "title": entry.get("label") or "",
        "mediatype": "movie",
    }
    if year:
        info["year"] = int(year)
    if minutes:
        info["duration"] = int(minutes) * 60
    li.setInfo("video", info)

    li.setProperty("IsPlayable", "true")
    return li


def _add_entry_items(entries: Iterable[Dict[str, Any]]) -> None:
    for entry in entries:
        q = entry.get("q")
        if not q:
            continue
        url = _url(view="play", q=int(q))
        xbmcplugin.addDirectoryItem(HANDLE, url, _make_entry_listitem(entry), isFolder=False)


def _add_dir(label: str, **params: Any) -> None:
    li = xbmcgui.ListItem(label=label)
    li.setArt({"icon": "DefaultFolder.png"})
    xbmcplugin.addDirectoryItem(HANDLE, _url(**params), li, isFolder=True)


def _finish(content: str = "movies") -> None:
    xbmcplugin.setContent(HANDLE, content)
    xbmcplugin.endOfDirectory(HANDLE)


# ---------- views ----------

def view_root() -> None:
    _add_dir(_t(30100), view="special", key="recently_edited")
    _add_dir(_t(30101), view="special", key="highly_ranked")
    _add_dir(_t(30102), view="special", key="popular_entries")
    _add_dir(_t(30103), view="sections")
    _add_dir(_t(30104), view="search")
    _finish(content="files")


def view_special(key: str, offset: int = 0) -> None:
    api = _api()
    try:
        data = api.get_special(key, offset=offset, limit=PAGE_SIZE)
    except ApiError as exc:
        _notify(str(exc))
        _finish()
        return
    entries = data.get("entries") or []
    total = int(data.get("total") or 0)
    _add_entry_items(entries)
    next_offset = offset + PAGE_SIZE
    if next_offset < total:
        _add_dir(_t(30110), view="special", key=key, offset=next_offset)
    _finish()


def view_sections() -> None:
    api = _api()
    try:
        sections = api.get_all_sections()
    except ApiError as exc:
        _notify(str(exc))
        _finish(content="files")
        return
    # API returns rows from `vw_section_property_q` — group by section_q
    # so we present each genre/decade once even if it appears under
    # multiple Wikidata properties.
    seen: Dict[int, Dict[str, Any]] = {}
    for s in sections:
        section_q = int(s.get("section_q") or 0)
        if section_q and section_q not in seen:
            seen[section_q] = s
    for s in sorted(seen.values(), key=lambda x: (x.get("label") or "").lower()):
        label = s.get("label") or f"Q{s.get('section_q')}"
        _add_dir(
            label,
            view="section",
            q=int(s["section_q"]),
            prop=int(s["property"]),
        )
    _finish(content="files")


def view_section(section_q: int, prop: int, offset: int = 0) -> None:
    api = _api()
    try:
        data = api.get_section(section_q, prop, offset=offset, limit=PAGE_SIZE)
    except ApiError as exc:
        _notify(str(exc))
        _finish()
        return
    entries = data.get("entries") or data.get("items") or []
    _add_entry_items(entries)
    total = int(data.get("total") or 0)
    next_offset = offset + PAGE_SIZE
    if next_offset < total:
        _add_dir(
            _t(30110),
            view="section",
            q=section_q,
            prop=prop,
            offset=next_offset,
        )
    _finish()


def view_search() -> None:
    keyboard = xbmc.Keyboard("", _t(30104))
    keyboard.doModal()
    if not keyboard.isConfirmed():
        _finish()
        return
    query = keyboard.getText().strip()
    if not query:
        _finish()
        return
    api = _api()
    try:
        data = api.search(query)
    except ApiError as exc:
        _notify(str(exc))
        _finish()
        return
    _add_entry_items(data.get("entries") or [])
    _finish()


def view_play(q: int) -> None:
    api = _api()
    try:
        entry = api.get_entry(q)
    except ApiError as exc:
        _notify(str(exc))
        xbmcplugin.setResolvedUrl(HANDLE, False, xbmcgui.ListItem())
        return
    if not entry:
        _notify(_t(30120))
        xbmcplugin.setResolvedUrl(HANDLE, False, xbmcgui.ListItem())
        return

    chosen = pick_best_file(entry.get("entry_files") or [])
    if not chosen:
        _notify(_t(30121))
        xbmcplugin.setResolvedUrl(HANDLE, False, xbmcgui.ListItem())
        return

    url = resolve_play_url(int(chosen.get("property", 0)), chosen.get("key", ""))
    if not url:
        _notify(_t(30121))
        xbmcplugin.setResolvedUrl(HANDLE, False, xbmcgui.ListItem())
        return

    li = _make_entry_listitem(entry)
    li.setPath(url)
    xbmcplugin.setResolvedUrl(HANDLE, True, li)
    api.log_event("play_page_loaded", q)


# ---------- dispatch ----------

def main() -> None:
    raw = sys.argv[2][1:] if len(sys.argv) > 2 else ""
    params = dict(urllib.parse.parse_qsl(raw))
    view = params.get("view")

    if view is None:
        view_root()
    elif view == "special":
        view_special(params["key"], int(params.get("offset", 0)))
    elif view == "sections":
        view_sections()
    elif view == "section":
        view_section(
            int(params["q"]), int(params["prop"]), int(params.get("offset", 0))
        )
    elif view == "search":
        view_search()
    elif view == "play":
        view_play(int(params["q"]))
    else:
        view_root()


if __name__ == "__main__":
    main()
