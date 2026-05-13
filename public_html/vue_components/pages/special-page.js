/**
 * <special-page> — full grid for one of the main-page pseudo-sections
 * (recently_edited, highly_ranked, popular_entries, female_directors,
 * recently_viewed, …). Companion to <section-page>; same layout and the
 * same infinite-scroll pagination model. Data comes from the get_special
 * API action keyed by string instead of a Wikidata Q-id.
 *
 * The `recently_viewed` key is purely client-side — capped at 10 items
 * in useRecentlyViewed, so it bypasses pagination entirely.
 */

import { ttMixin } from '../../resources/vue_es6/state.js';
import { useFetch } from '../composables/useFetch.js';
import { useLog } from '../composables/useLog.js';
import { useRecentlyViewed } from '../composables/useRecentlyViewed.js';
import { useInfiniteScroll } from '../composables/useInfiniteScroll.js';

const { ref, computed } = Vue;

const PAGE_SIZE = 50;

export default {
    name: 'SpecialPage',
    mixins: [ttMixin],
    // Router maps the path's `:key` param to `specialKey` (see router.js) —
    // `key` is reserved by Vue, so we use a different name in component land.
    props: ['specialKey'],
    setup(props) {
        const { error: fetchError, run } = useFetch();
        const { log } = useLog();
        const { items: recentlyViewedItems } = useRecentlyViewed();

        log('special_loaded', { q: props.specialKey });

        const isRecentlyViewed = props.specialKey === 'recently_viewed';

        // ---- recently_viewed: synchronous client-side list ----------
        // Capped at 10 items in useRecentlyViewed, so the page just renders
        // them directly with no observer/sentinel machinery.
        if (isRecentlyViewed) {
            const section = computed(() => ({
                key: props.specialKey,
                title_key: props.specialKey,
                title: props.specialKey,
                total: recentlyViewedItems.value.length,
                entries: recentlyViewedItems.value,
            }));
            return {
                isRecentlyViewed: true,
                section,
                error:       fetchError,
                loading:     ref(false),
                loadingMore: ref(false),
                hasMore:     ref(false),
                itemCount:   computed(() => recentlyViewedItems.value.length),
                sentinelEl:  ref(null),
                retry:       () => {},
            };
        }

        // ---- everything else: paginated server-side -----------------
        const infinite = useInfiniteScroll({
            pageSize: PAGE_SIZE,
            fetchPage: async (offset, limit) => {
                const url = './api.php?action=get_special'
                    + '&key=' + encodeURIComponent(props.specialKey)
                    + '&offset=' + offset
                    + '&limit=' + limit;
                const j = await run(url);
                if (!j || !j.data) return null;
                return {
                    items: j.data.entries || [],
                    total: typeof j.data.total === 'number' ? j.data.total : null,
                };
            },
        });

        const error = computed(() => fetchError.value || infinite.error.value);

        const section = computed(() => ({
            key: props.specialKey,
            title_key: props.specialKey,
            title: props.specialKey,
            total: infinite.total.value,
            entries: infinite.items.value,
        }));

        return {
            isRecentlyViewed: false,
            section,
            error,
            loading:     infinite.loading,
            loadingMore: infinite.loadingMore,
            hasMore:     infinite.hasMore,
            sentinelEl:  infinite.sentinelEl,
            retry:       infinite.retry,
            itemCount:   computed(() => infinite.items.value.length),
        };
    },
    template: `
        <div class="container-fluid">
            <page-header></page-header>
            <div class="row" style="width:100%;">
                <error-banner v-if="error && !itemCount" :error="error"></error-banner>
                <skeleton-row v-else-if="loading" :count="12"></skeleton-row>
                <div v-else>
                    <section-row :section="section" nolink="1" multi_row="1"></section-row>

                    <div v-if="loadingMore" class="infinite-loading text-center" aria-busy="true">
                        <span class="sr-only" tt="loading">Loading</span>
                        <skeleton-row :count="6"></skeleton-row>
                    </div>

                    <div v-if="hasMore" ref="sentinelEl" class="infinite-sentinel" aria-hidden="true"></div>

                    <div v-if="error && itemCount" class="infinite-error text-center">
                        <span tt="error_loading_more">Couldn't load more.</span>
                        <button type="button" class="btn btn-sm btn-link" @click="retry" tt="try_again">Try again</button>
                    </div>

                    <div v-if="!hasMore && !error && itemCount && !isRecentlyViewed" class="infinite-end text-center text-muted" tt="end_of_list">
                        &mdash;
                    </div>
                </div>
            </div>
        </div>
    `,
};
