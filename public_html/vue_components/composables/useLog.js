/**
 * useLog — fire-and-forget logging to the api.php `log` endpoint.
 *
 * The logging endpoint accepts arbitrary `event` plus a small set of
 * known parameters. Errors are swallowed silently — logging must never
 * block the UI.
 *
 * Usage:
 *   const { log } = useLog();
 *   log('play_page_loaded', { source_prop: '10', source_key: 'Foo.webm' });
 */

const API = './api.php';

export function useLog() {
    function log(event, params = {}) {
        const query = new URLSearchParams({ action: 'log', event });
        for (const [k, v] of Object.entries(params)) {
            if (v === undefined || v === null) continue;
            query.set(k, String(v));
        }
        fetch(`${API}?${query.toString()}`).catch(() => {});
    }
    return { log };
}
