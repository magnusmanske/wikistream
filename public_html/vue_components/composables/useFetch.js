/**
 * useFetch — minimal wrapper around `fetch()` that captures errors in a
 * reactive ref. Pages render an <error-banner> when `error.value` is
 * non-null. Network failures, non-2xx responses, and JSON-parse errors
 * are all surfaced uniformly.
 *
 * Returns `null` on failure (and sets `error.value`); returns the parsed
 * JSON body on success.
 *
 * Usage:
 *   const { error, run } = useFetch();
 *   onMounted(async () => {
 *       const j = await run(`./api.php?action=get_entry&q=${q}`);
 *       if (j) entry.value = j.data;
 *   });
 *
 *   <error-banner v-if="error" :error="error"></error-banner>
 *   <skeleton-row v-else-if="loading"></skeleton-row>
 *   …
 */

const { ref } = Vue;

export function useFetch() {
    const error = ref(null);

    async function run(url, options) {
        error.value = null;
        try {
            const res = await fetch(url, options);
            // api.php always returns a JSON envelope { status, data?, ... }
            // even on error. Parse defensively so a malformed body still
            // surfaces as an error rather than throwing.
            let body = null;
            try { body = await res.json(); } catch (_) {}

            if (!res.ok) {
                error.value = {
                    status: res.status,
                    message: (body && body.status && body.status !== 'OK')
                        ? body.status
                        : (res.statusText || `HTTP ${res.status}`),
                };
                return null;
            }
            if (body === null) {
                error.value = { status: 0, message: 'Invalid response from server' };
                return null;
            }
            // Defensive: api.php sets HTTP 4xx/5xx on errors, but a 200 OK
            // with `status !== 'OK'` is still a logical failure.
            if (body.status && body.status !== 'OK') {
                error.value = { status: 0, message: body.status };
                return null;
            }
            return body;
        } catch (e) {
            error.value = {
                status: 0,
                message: (e && e.message) || 'Network error',
            };
            return null;
        }
    }

    return { error, run };
}
