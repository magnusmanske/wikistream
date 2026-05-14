"""Thin JSON client for the WikiFlix / WikiVibes `api.php` endpoint."""

from __future__ import annotations

import json
import urllib.error
import urllib.parse
import urllib.request
from typing import Any, Dict, List, Optional

DEFAULT_TIMEOUT = 15  # seconds
USER_AGENT = "plugin.video.wikiflix/0.1 (+https://wikiflix.toolforge.org/)"


class ApiError(Exception):
    """Raised when the backend returns a non-OK status or the request fails."""


class WikiStreamApi:
    def __init__(self, base_url: str, language: str = "en") -> None:
        # Normalise so callers don't have to think about the trailing slash.
        self.base_url = base_url.rstrip("/")
        self.language = language

    # ---------- low-level ----------

    def _call(self, action: str, **params: Any) -> Dict[str, Any]:
        query = {"action": action, "language": self.language}
        for k, v in params.items():
            if v is None:
                continue
            query[k] = v
        url = f"{self.base_url}/api.php?{urllib.parse.urlencode(query)}"
        req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
        try:
            with urllib.request.urlopen(req, timeout=DEFAULT_TIMEOUT) as resp:
                payload = json.loads(resp.read().decode("utf-8"))
        except (urllib.error.URLError, json.JSONDecodeError, TimeoutError) as exc:
            raise ApiError(f"Request failed: {exc}") from exc

        status = payload.get("status")
        if status != "OK":
            raise ApiError(f"API error: {status}")
        return payload

    # ---------- typed wrappers ----------

    def get_all_sections(self) -> List[Dict[str, Any]]:
        """Top-level section catalogue (genres, decades, …)."""
        return self._call("get_all_sections").get("data", []) or []

    def get_section(
        self, section_q: int, prop: int, offset: int = 0, limit: int = 50
    ) -> Dict[str, Any]:
        return self._call(
            "get_section", q=section_q, prop=prop, offset=offset, limit=limit
        ).get("data", {}) or {}

    def get_special(
        self, key: str, offset: int = 0, limit: int = 50
    ) -> Dict[str, Any]:
        return self._call(
            "get_special", key=key, offset=offset, limit=limit
        ).get("data", {}) or {}

    def get_entry(self, q: int) -> Optional[Dict[str, Any]]:
        return self._call("get_entry", q=q).get("data")

    def search(self, query: str) -> Dict[str, Any]:
        return self._call("search", query=query).get("data", {}) or {}

    def log_event(self, event: str, q: int) -> None:
        # Best-effort — never let logging break navigation.
        try:
            self._call("log", event=event, q=q)
        except ApiError:
            pass
