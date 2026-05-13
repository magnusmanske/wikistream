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
            if (!res.ok) {
                error.value = {
                    status: res.status,
                    message: res.statusText || `HTTP ${res.status}`,
                };
                return null;
            }
            return await res.json();
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
