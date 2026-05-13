/**
 * useInfiniteScroll — page-by-page loader with an IntersectionObserver
 * sentinel. The default UX for long thumbnail grids: render a sensible
 * initial page, then fetch more when the user scrolls near the bottom.
 *
 * Usage:
 *   const {
 *       items, total, loading, loadingMore, error, hasMore,
 *       loadMore, retry, sentinelEl,
 *   } = useInfiniteScroll({
 *       fetchPage: async (offset, limit) => ({ items: [...], total: N }),
 *       pageSize: 50,
 *   });
 *
 *   // template
 *   <div v-if="hasMore" ref="sentinelEl" class="infinite-sentinel"></div>
 *   <button v-if="error" @click="retry">Try again</button>
 *
 * Notes for the wikistream codebase:
 *   - Dedup by `entry.q`, since the same Q-id appearing in two pages would
 *     trigger Vue's :key warning and double-render.
 *   - On unmount, the IntersectionObserver is disconnected. Pending fetches
 *     resolve harmlessly into the detached ref.
 *   - `loadMore()` is exported so a "Load more" button can drive pagination
 *     for keyboard / screen-reader users (or browsers without
 *     IntersectionObserver — there the sentinel is inert).
 */

const { ref, computed, onMounted, onUnmounted, watch, nextTick } = Vue;

export function useInfiniteScroll({
    fetchPage,
    pageSize = 50,
    rootMargin = '600px 0px',
} = {}) {
    if (typeof fetchPage !== 'function') {
        throw new Error('useInfiniteScroll: fetchPage must be a function');
    }

    const items        = ref([]);
    const total        = ref(null);     // null = API did not declare a total
    const loading      = ref(true);     // initial page only
    const loadingMore  = ref(false);    // any subsequent page
    const error        = ref(null);
    const offset       = ref(0);
    const lastPageFull = ref(true);     // until proven otherwise

    // De-duplicate across pages by `q`. Items lacking a q are appended
    // unconditionally (callers can supply `id` instead via fetchPage if they
    // need a different key).
    const seen = new Set();

    const hasMore = computed(() => {
        // While initial load is in flight there is no sentinel yet.
        if (loading.value) return false;
        if (error.value)   return false;
        if (total.value !== null) return items.value.length < total.value;
        return lastPageFull.value;
    });

    async function load() {
        // Re-entrancy guard. IntersectionObserver can fire several events in
        // quick succession before the new items grow the page.
        if (loadingMore.value) return;
        if (!loading.value && !hasMore.value) return;

        if (!loading.value) loadingMore.value = true;
        error.value = null;

        let page;
        try {
            page = await fetchPage(offset.value, pageSize);
        } catch (e) {
            error.value = { message: (e && e.message) || 'Network error' };
            loadingMore.value = false;
            loading.value = false;
            return;
        }

        if (page === null || page === undefined) {
            error.value = { message: 'Failed to load' };
            loadingMore.value = false;
            loading.value = false;
            return;
        }

        const fetched = Array.isArray(page.items) ? page.items : [];
        for (const it of fetched) {
            const key = it && (it.q ?? it.id);
            if (key !== undefined && key !== null) {
                if (seen.has(key)) continue;
                seen.add(key);
            }
            items.value.push(it);
        }

        if (typeof page.total === 'number' && Number.isFinite(page.total)) {
            total.value = page.total;
        }

        // If the server gave us back fewer than we asked for, there is no
        // more — even if `total` happens to be wrong.
        lastPageFull.value = fetched.length >= pageSize;
        offset.value += fetched.length;

        loadingMore.value = false;
        loading.value = false;
    }

    function retry() {
        if (loadingMore.value) return;
        error.value = null;
        load();
    }

    // ---- IntersectionObserver wiring ---------------------------------

    const sentinelEl = ref(null);
    let observer = null;

    function attach(el) {
        if (observer) {
            observer.disconnect();
            observer = null;
        }
        if (!el) return;
        if (typeof IntersectionObserver === 'undefined') {
            // Very old browser: sentinel is inert, manual loadMore() still works.
            return;
        }
        observer = new IntersectionObserver(
            (entries) => {
                for (const e of entries) {
                    if (e.isIntersecting) load();
                }
            },
            { rootMargin },
        );
        observer.observe(el);
    }

    watch(sentinelEl, (el) => attach(el));

    onMounted(async () => {
        await load();
        // Sentinel only renders when hasMore becomes true, which happens
        // after the first load returns. The ref might not be hooked up yet;
        // a nextTick is enough.
        await nextTick();
        if (sentinelEl.value) attach(sentinelEl.value);
    });

    onUnmounted(() => {
        if (observer) observer.disconnect();
        observer = null;
    });

    return {
        items, total, loading, loadingMore, error, hasMore,
        loadMore: load,
        retry,
        sentinelEl,
    };
}
