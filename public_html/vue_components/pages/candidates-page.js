/**
 * <candidates-page> — items that don't yet have a video source. Helps
 * editors find candidates to add.
 *
 * `:offset` is the absolute item offset; we expose it as a 0-based batch
 * index to <batch-navigator>.
 */

import { ttMixin } from '../../resources/vue_es6/state.js';

const { ref, onMounted, computed } = Vue;

const BATCH = 50;

export default {
    name: 'CandidatesPage',
    mixins: [ttMixin],
    props: ['offset'],
    setup(props) {
        const loading = ref(true);
        const candidates = ref([]);
        const total_candidates = ref(0);

        const current_batch = computed(() => Math.floor((parseInt(props.offset, 10) || 0) / BATCH));

        onMounted(async () => {
            const o = parseInt(props.offset, 10) || 0;
            try {
                const res = await fetch(`./api.php?action=get_candidate_items&offset=${o}`);
                const j = await res.json();
                candidates.value = j.data || [];
                total_candidates.value = j.total_candidates || 0;
            } finally {
                loading.value = false;
            }
        });

        function getLabelYear(c) {
            let query = `"${c.title}"`;
            if (typeof c.year !== 'undefined' && c.year !== '') query += ` ${c.year}`;
            return encodeURIComponent(query);
        }

        return { loading, candidates, total_candidates, current_batch, getLabelYear, BATCH };
    },
    methods: {
        set_current(new_batch) {
            this.$router.push('/candidates/' + (new_batch * BATCH));
        },
    },
    template: `
        <div class="container-fluid">
            <page-header></page-header>
            <div class="row" style="width:100%;">
                <div v-if="loading">
                    <i tt="loading"></i>
                </div>
                <div v-else>
                    <div style="width: 100%;">
                        <div style="display: flex;">
                            <div style="flex-grow: 1;"></div>
                            <div class="lead" tt="candidates_lead" style="width: 50%; margin-bottom: 1rem;"></div>
                            <div style="flex-grow: 1;"></div>
                        </div>
                        <div style="display: flex;">
                            <div style="flex-grow: 1;"></div>
                            <batch-navigator :batch_size="BATCH" :total="total_candidates" :current="current_batch" @set-current="set_current($event)"></batch-navigator>
                            <div style="flex-grow: 1;"></div>
                        </div>
                        <div style="display: flex;">
                            <div style="flex-grow: 1;"></div>
                            <table class="table table-striped" style="width: 50%;">
                                <thead>
                                    <tr>
                                        <th tt="entry"></th>
                                        <th tt="year"></th>
                                        <th tt="minutes"></th>
                                        <th tt="sites"></th>
                                        <th tt="internet_archive_search"></th>
                                        <th tt="commons_search"></th>
                                        <th tt="youtube_search"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="c in candidates" :key="c.q">
                                        <td>
                                            <wd-link :item="'Q'+c.q"></wd-link>
                                        </td>
                                        <td>{{c.year}}</td>
                                        <td>{{c.minutes}}</td>
                                        <td>{{c.sites}}</td>
                                        <td nowrap>
                                            <a class="btn btn-outline-primary" :href="'https://archive.org/details/movies?query='+getLabelYear(c)" target="_blank" rel="noopener" tt="search"></a>
                                            <span v-if='c.ia_results===null || typeof c.ia_results=="undefined"'>?</span>
                                            <span v-else-if="c.ia_results==0" style="color:red;" tt_title="has_no_search_results">&times;</span>
                                            <span v-else style="color: green;" tt_title="has_search_results">✓</span>
                                        </td>
                                        <td nowrap>
                                            <a class="btn btn-outline-primary" :href="'https://commons.wikimedia.org/w/index.php?type=video&title=Special:MediaSearch&go=Go&search='+getLabelYear(c)" target="_blank" rel="noopener" tt="search"></a>
                                            <span v-if='c.commons_results===null || typeof c.commons_results=="undefined"'>?</span>
                                            <span v-else-if="c.commons_results==0" style="color:red;" tt_title="has_no_search_results">&times;</span>
                                            <span v-else style="color: green;" tt_title="has_search_results">✓</span>
                                        </td>
                                        <td nowrap>
                                            <a class="btn btn-outline-primary" :href="'https://www.youtube.com/results?search_query='+getLabelYear(c)" target="_blank" rel="noopener" tt="search"></a>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div style="flex-grow: 1;"></div>
                        </div>
                        <div style="display: flex;">
                            <div style="flex-grow: 1;"></div>
                            <batch-navigator :batch_size="BATCH" :total="total_candidates" :current="current_batch" @set-current="set_current($event)"></batch-navigator>
                            <div style="flex-grow: 1;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `,
};
