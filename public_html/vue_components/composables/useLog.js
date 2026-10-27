/**
 * useLog — fire-and-forget logging to the api.php `log` endpoint.
 *
 * The logging endpoint accepts arbitrary `event` plus a small set of
 * known parameters. Errors are swallowed silently — logging must never
 * block the UI.
 *
 * In production, each event is also counted (tool + event name only —
 * no item IDs, no cookies, no referrer) by the magnustools usage logger.
 *
 * Usage:
 *   const { log } = useLog();
 *   log('play_page_loaded', { source_prop: '10', source_key: 'Foo.webm' });
 */

const API = './api.php';
const USAGE_LOGGER = 'https://magnustools.toolforge.org/logger/';

function countUse(event) {
    const tool = window.config?.misc?.toolname;
    // Only the live tool counts, not local dev or forks.
    if (!tool || location.hostname !== `${tool}.toolforge.org`) return;
    const query = new URLSearchParams({ tool, method: event });
    fetch(`${USAGE_LOGGER}?${query.toString()}`, {
        mode: 'no-cors',
        credentials: 'omit',
        referrerPolicy: 'no-referrer',
        keepalive: true,
    }).catch(() => {});
}

export function useLog() {
    function log(event, params = {}) {
        const query = new URLSearchParams({ action: 'log', event });
        for (const [k, v] of Object.entries(params)) {
            if (v === undefined || v === null) continue;
            query.set(k, String(v));
        }
        fetch(`${API}?${query.toString()}`).catch(() => {});
        countUse(event);
    }
    return { log };
}
