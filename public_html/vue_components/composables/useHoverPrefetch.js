/**
 * useHoverPrefetch — warm the browser cache for a URL when the user
 * hovers over a target element for at least 150 ms. Repeats are
 * suppressed via a per-session Set, and the total number of prefetches
 * is capped so an over-eager mouse can't DDoS the server.
 *
 * Most effective once the API endpoints set `Cache-Control` headers
 * (see audit item #17); without those, modern browsers still keep the
 * response in their in-memory cache for a short while.
 *
 * Usage:
 *   const { start, cancel } = useHoverPrefetch();
 *   <div @mouseenter="start(url)" @mouseleave="cancel()">…</div>
 */

const HOVER_DELAY_MS = 150;
const MAX_PREFETCH_PER_SESSION = 50;
const prefetched = new Set();

export function useHoverPrefetch() {
    let timer = null;

    function start(url) {
        if (!url) return;
        if (prefetched.has(url)) return;
        if (prefetched.size >= MAX_PREFETCH_PER_SESSION) return;
        if (timer) clearTimeout(timer);
        timer = setTimeout(() => {
            timer = null;
            fetch(url, { credentials: 'same-origin' })
                .then(() => prefetched.add(url))
                .catch(() => { /* network hiccup — will retry next time */ });
        }, HOVER_DELAY_MS);
    }

    function cancel() {
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }
    }

    return { start, cancel };
}
