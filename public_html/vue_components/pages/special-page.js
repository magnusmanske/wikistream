/**
 * <special-page> — full grid for one of the main-page pseudo-sections
 * (recently_edited, highly_ranked, popular_entries, female_directors,
 * recently_viewed, …). Companion to <section-page>; same layout, but
 * the data source is the get_special API action keyed by string instead
 * of a Wikidata Q-id.
 *
 * The `recently_viewed` key is purely client-side — read from the
 * useRecentlyViewed composable instead of the backend.
 */

import { ttMixin } from '../../resources/vue_es6/state.js';
import { useFetch } from '../composables/useFetch.js';
import { useLog } from '../composables/useLog.js';
import { useRecentlyViewed } from '../composables/useRecentlyViewed.js';

const { ref, onMounted, computed } = Vue;

export default {
    name: 'SpecialPage',
    mixins: [ttMixin],
    // Router maps the path's `:key` param to `specialKey` (see router.js) —
    // `key` is reserved by Vue, so we use a different name in component land.
    props: ['specialKey'],
    setup(props) {
        const loading = ref(true);
        const entries = ref([]);
        const { error, run } = useFetch();
        const { log } = useLog();
        const { items: recentlyViewedItems } = useRecentlyViewed();

        const section = computed(() => ({
            title_key: props.specialKey,
            title: props.specialKey,
            entries: entries.value,
        }));

        onMounted(async () => {
            log('special_loaded', { q: props.specialKey });

            // `recently_viewed` lives entirely in localStorage.
            if (props.specialKey === 'recently_viewed') {
                entries.value = recentlyViewedItems.value;
                loading.value = false;
                return;
            }

            const j = await run(
                './api.php?action=get_special&key=' + encodeURIComponent(props.specialKey),
            );
            if (j && j.data) entries.value = j.data.entries || [];
            loading.value = false;
        });

        return { loading, section, error };
    },
    template: `
        <div class="container-fluid">
            <page-header></page-header>
            <div class="row" style="width:100%;">
                <error-banner v-if="error" :error="error"></error-banner>
                <skeleton-row v-else-if="loading" :count="12"></skeleton-row>
                <div v-else>
                    <section-row :section="section" nolink="1" multi_row="1"></section-row>
                </div>
            </div>
        </div>
    `,
};
