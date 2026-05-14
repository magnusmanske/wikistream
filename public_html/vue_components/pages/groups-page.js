/**
 * <groups-page> — every group (series, franchise, …) as a horizontal
 * thumbnail bar. Loaded in pages via get_paginated_groups, with more
 * pulled in as the user scrolls down. Each row is a <section-row> with
 * `link_prefix="/group/"` so the heading deep-links to the group page.
 */

import { ttMixin } from '../../resources/vue_es6/state.js';
import { useFetch } from '../composables/useFetch.js';
import { useInfiniteScroll } from '../composables/useInfiniteScroll.js';

export default {
    name: 'GroupsPage',
    mixins: [ttMixin],
    setup() {
        const { error: fetchError, run } = useFetch();

        const {
            items: groups, loading, loadingMore, error: scrollError,
            hasMore, retry, sentinelEl,
        } = useInfiniteScroll({
            pageSize: 10,
            async fetchPage(offset, limit) {
                const j = await run(
                    `./api.php?action=get_paginated_groups&offset=${offset}&limit=${limit}`,
                );
                const rows = Array.isArray(j?.data) ? j.data : [];
                return { items: rows };
            },
        });

        return { groups, loading, loadingMore, hasMore, sentinelEl, error: scrollError, fetchError, retry };
    },
    template: `
        <div class="container-fluid">
            <page-header></page-header>
            <div class="row" style="width:100%;">
                <error-banner v-if="fetchError" :error="fetchError"></error-banner>
                <div v-else-if="loading" style="width: 100%;">
                    <skeleton-row :count="6"></skeleton-row>
                </div>
                <div v-else style="width: 100%;">
                    <section-row v-for="g in groups" :key="'group-'+g.q" :section="g" link_prefix="/group/"></section-row>
                    <div v-if="hasMore" ref="sentinelEl" class="infinite-sentinel" style="height: 1px;"></div>
                    <div v-if="loadingMore" style="margin: 1rem 0;">
                        <skeleton-row :count="2"></skeleton-row>
                    </div>
                    <div v-if="error" style="margin: 1rem 0; text-align: center;">
                        <error-banner :error="error"></error-banner>
                        <button class="btn btn-outline-light" @click="retry" tt="try_again">Try again</button>
                    </div>
                </div>
            </div>
        </div>
    `,
};
