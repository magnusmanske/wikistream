/**
 * useRecentlyViewed — localStorage-backed list of the entries the user
 * has looked at most recently. Surfaced as a row at the top of the main
 * page so users can resume what they were browsing.
 *
 * Stores at most MAX_ITEMS records, deduplicated by `q`. The shape of
 * each record matches what <entry-thumb> expects: { q, title, image,
 * year, minutes, is_silent, files? }.
 */

const { ref } = Vue;

const STORAGE_KEY = 'wikistream.recentlyViewed';
const MAX_ITEMS = 10;

function readStorage() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return [];
        const arr = JSON.parse(raw);
        return Array.isArray(arr) ? arr : [];
    } catch (_) {
        return [];
    }
}

function writeStorage(arr) {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(arr));
    } catch (_) { /* quota or disabled — silent */ }
}

const items = ref(readStorage());

export function recordView(entry) {
    if (!entry || !entry.q) return;
    const q = parseInt(entry.q, 10);
    if (!Number.isFinite(q)) return;
    // Remove any existing entry for this Q, prepend the fresh one, cap.
    const filtered = items.value.filter((e) => parseInt(e.q, 10) !== q);
    const record = {
        q,
        title: entry.title ?? '',
        image: entry.image ?? null,
        year: entry.year ?? null,
        minutes: entry.minutes ?? null,
        is_silent: entry.is_silent ?? 0,
        files: entry.files ?? [],
    };
    items.value = [record, ...filtered].slice(0, MAX_ITEMS);
    writeStorage(items.value);
}

export function clearViews() {
    items.value = [];
    writeStorage(items.value);
}

export function removeView(q) {
    const num = parseInt(q, 10);
    if (!Number.isFinite(num)) return;
    items.value = items.value.filter((e) => parseInt(e.q, 10) !== num);
    writeStorage(items.value);
}

export function useRecentlyViewed() {
    return { items, recordView, clearViews, removeView };
}
