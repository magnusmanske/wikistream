/**
 * <year-page> — decade table on top, optional single-year grid below.
 *
 * The decade table comes from `window.config.years`. When :year is in
 * the URL, fetch and display that year's entries.
 */

import { ttMixin } from '../../resources/vue_es6/state.js';
import { useLog } from '../composables/useLog.js';
import { useFetch } from '../composables/useFetch.js';

const { ref, onMounted } = Vue;

export default {
    name: 'YearPage',
    mixins: [ttMixin],
    props: ['year'],
    setup(props) {
        const loading = ref(true);
        const entries = ref([]);
        const config = window.config || {};

        const { log } = useLog();
        const { error, run } = useFetch();

        onMounted(async () => {
            log('year_loaded', { q: props.year ?? 0 });

            if (typeof props.year === 'undefined') {
                loading.value = false;
                return;
            }
            const j = await run(
                './api.php?action=get_items_by_year&year=' +
                    encodeURIComponent(props.year),
            );
            if (j) entries.value = j.data || [];
            loading.value = false;
        });

        return { loading, entries, config, error };
    },
    template: `
        <div class="container-fluid">
            <page-header></page-header>
            <div class="row" style="width:100%;">
                <div style="width: 100%;">
                    <div style="display: flex;">
                        <div style="flex-grow: 1;"></div>
                        <div style="width: 50%; margin-bottom: 1rem;">
                            <table>
                                <tr v-for="(years, decade) in config.years" :key="decade">
                                    <th>{{decade}}s</th>
                                    <td v-for="y in years" :key="y.year">
                                        <router-link :to="'/year/'+y.year" :title="y.cnt+' movies'">{{y.year}}</router-link>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        <div style="flex-grow: 1;"></div>
                    </div>
                    <error-banner v-if="error" :error="error"></error-banner>
                    <skeleton-row v-else-if="loading" :count="12"></skeleton-row>
                    <div v-else-if="entries.length>0">
                        <h3>{{year}}</h3>
                        <section-row :entries="entries" multi_row="1"></section-row>
                    </div>
                </div>
            </div>
        </div>
    `,
};
