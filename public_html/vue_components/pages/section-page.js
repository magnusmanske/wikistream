/**
 * <section-page> — full grid of entries for one section, paginated via
 * infinite scroll. Initial page loads a screen's worth of thumbnails;
 * the IntersectionObserver-driven sentinel fetches the next page when
 * the user scrolls near the bottom.
 *
 * The page-1 response carries the section title and total, so the
 * header renders before the user scrolls. Subsequent pages just append
 * to the items list.
 */

import { ttMixin } from '../../resources/vue_es6/state.js';
import { useLog } from '../composables/useLog.js';
import { useFetch } from '../composables/useFetch.js';
import { useInfiniteScroll } from '../composables/useInfiniteScroll.js';

const { ref, computed } = Vue;

const PAGE_SIZE = 50;

export default {
    name: 'SectionPage',
    mixins: [ttMixin],
    props: ['section_q', 'section_prop'],
    setup(props) {
        const { log } = useLog();
        const { error: fetchError, run } = useFetch();

        // Captured from the first page so the header renders even before
        // the user scrolls. Subsequent pages don't include these.
        const title = ref('');
        const prop  = ref(null);

        log('section_loaded', { q: props.section_q });

        const infinite = useInfiniteScroll({
            pageSize: PAGE_SIZE,
            fetchPage: async (offset, limit) => {
                let url = './api.php?action=get_section'
                    + '&q=' + encodeURIComponent(props.section_q)
                    + '&offset=' + offset
                    + '&limit=' + limit;
                if (typeof props.section_prop !== 'undefined') {
                    url += '&prop=' + encodeURIComponent(props.section_prop);
                }
                const j = await run(url);
                if (!j || !j.data) return null;
                if (offset === 0) {
                    title.value = j.data.title || '';
                    prop.value  = j.data.prop ?? null;
                }
                return {
                    items: j.data.entries || [],
                    total: typeof j.data.total === 'number' ? j.data.total : null,
                };
            },
        });

        // useFetch's error ref reflects HTTP/network failures; useInfiniteScroll
        // also tracks its own error state. Surface whichever fired.
        const error = computed(() => fetchError.value || infinite.error.value);

        // Section-shaped object for <section-row>. .entries is the live
        // items list so each page-append re-renders the grid in place.
        const section = computed(() => ({
            q: props.section_q,
            title: title.value,
            prop: prop.value,
            total: infinite.total.value,
            entries: infinite.items.value,
        }));

        return {
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

                    <div v-if="!hasMore && !error && itemCount" class="infinite-end text-center text-muted" tt="end_of_list">
                        &mdash;
                    </div>
                </div>
            </div>
        </div>
    `,
};
