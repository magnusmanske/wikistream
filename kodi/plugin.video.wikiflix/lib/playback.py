"""Resolve an `entry_files` record from the WikiStream API to a Kodi-playable URL.

Source properties (see scripts/wikistream.php and play-page.js):
    P10    Commons video — direct stream via Special:FilePath (HTTP redirect to upload.wikimedia.org)
    P51    Commons audio — same trick as P10
    P724   Internet Archive identifier
    P1651  YouTube video id
    P4015  Vimeo video id
    P11731 DailyMotion video id

For non-Commons sources we delegate to whichever Kodi addon the user has
installed (e.g. plugin.video.youtube). If the addon isn't installed Kodi
shows a clear "missing dependency" dialog — better UX than us guessing.
"""

from __future__ import annotations

import urllib.parse
from typing import Optional


COMMONS_VIDEO_PROP = 10
COMMONS_AUDIO_PROP = 51
ARCHIVE_ORG_PROP = 724
VIMEO_PROP = 4015
DAILYMOTION_PROP = 11731
YOUTUBE_PROP = 1651


def _commons_filepath(key: str) -> str:
    return (
        "https://commons.wikimedia.org/wiki/Special:FilePath/"
        + urllib.parse.quote(key, safe="")
    )


def resolve_play_url(prop: int, key: str) -> Optional[str]:
    """Return a URL Kodi can hand to its player, or None if unsupported."""
    if not key:
        return None
    if prop in (COMMONS_VIDEO_PROP, COMMONS_AUDIO_PROP):
        return _commons_filepath(key)
    if prop == YOUTUBE_PROP:
        return f"plugin://plugin.video.youtube/play/?video_id={urllib.parse.quote(key, safe='')}"
    if prop == VIMEO_PROP:
        return f"plugin://plugin.video.vimeo/play/?video_id={urllib.parse.quote(key, safe='')}"
    if prop == DAILYMOTION_PROP:
        return f"plugin://plugin.video.dailymotion_com/?mode=playVideo&url={urllib.parse.quote(key, safe='')}"
    if prop == ARCHIVE_ORG_PROP:
        return f"plugin://plugin.video.archive.org/?mode=play&identifier={urllib.parse.quote(key, safe='')}"
    return None


def commons_thumb_url(filename: str, width: int = 400) -> str:
    """Build a Commons thumbnail URL for an item's `image` field."""
    return (
        "https://commons.wikimedia.org/wiki/Special:FilePath/"
        + urllib.parse.quote(filename, safe="")
        + f"?width={width}"
    )


def pick_best_file(entry_files):
    """From an entry's `files` list, choose the file we'd most like to play.

    Prefer direct Commons video, then Commons audio, then any embeddable
    third-party source. Skip trailers in favour of the main feature.
    """
    if not entry_files:
        return None
    priority = [
        COMMONS_VIDEO_PROP,
        COMMONS_AUDIO_PROP,
        ARCHIVE_ORG_PROP,
        YOUTUBE_PROP,
        VIMEO_PROP,
        DAILYMOTION_PROP,
    ]
    by_prop = {}
    for f in entry_files:
        prop = int(f.get("property", 0))
        if f.get("is_trailer"):
            continue
        by_prop.setdefault(prop, f)
    for prop in priority:
        if prop in by_prop:
            return by_prop[prop]
    return None
