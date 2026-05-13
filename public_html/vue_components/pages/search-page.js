/**
 * <search-page> — search across entries, sections, and people.
 *
 * URL `:initial_query` (optional) triggers a search on load; otherwise
 * the page presents an empty input ready for typing.
 */

import { ttMixin } from '../../resources/vue_es6/state.js';

const { ref, onMounted } = Vue;

export default {
    name: 'SearchPage',
    mixins: [ttMixin],
    props: ['initial_query'],
    setup(props) {
        const loading = ref(true);
        const query = ref('');
        const entries = ref([]);
        const sections = ref([]);
        const people = ref([]);

        async function run_search() {
            if (query.value === '') return;
            loading.value = true;
            try {
                const res = await fetch(
                    './api.php?action=search&query=' +
                        encodeURIComponent(query.value),
                );
                const j = await res.json();
                entries.value = j.data?.entries || [];
                sections.value = j.data?.sections || [];
                people.value = j.data?.people || [];
            } finally {
                loading.value = false;
                focusInput();
            }
        }

        function focusInput() {
            const el = document.getElementById('main-search-query');
            if (el) el.focus();
        }

        onMounted(() => {
            if (typeof props.initial_query !== 'undefined') {
                query.value = props.initial_query;
                run_search();
            } else {
                loading.value = false;
                focusInput();
            }
        });

        return { loading, query, entries, sections, people, run_search };
    },
    methods: {
        // $router only available on Options-API `this`.
        run_search_from_form() {
            if (this.query === '') return;
            this.$router.push('/search/' + this.query);
        },
    },
    template: `
        <div class="container-fluid">
            <page-header></page-header>
            <div class="row" style="width:100%;">
                <div style="width:5%;"></div>
                <div style="width: 90%">
                    <div style="display:flex;">
                        <div style="flex-grow: 1;"></div>
                        <form style="display: flex;" @submit.prevent="run_search_from_form">
                            <input id="main-search-query" class="form-control" style="width: 20rem;" type="text" v-model="query" tt_title="enter_to_search" />
                            <input type="submit" class="btn" />
                        </form>
                        <div style="flex-grow: 1;"></div>
                    </div>

                    <div v-if="loading"><i tt="loading"></i></div>
                    <div v-else-if="query==''"></div>
                    <div v-else-if="entries.length+sections.length+people.length==0">
                        <i tt="no_search_results"></i>
                    </div>
                    <div v-else style="width: 100%;">
                        <div v-if="entries.length>0">
                            <h3 tt="entries"></h3>
                            <section-row :entries="entries" multi_row="1"></section-row>
                        </div>
                        <div v-if="people.length>0">
                            <h3 tt="people"></h3>
                            <div style="display: flex; flex-wrap: 1;">
                                <person-thumb v-for="person in people" :key="person.q" :person="person"></person-thumb>
                            </div>
                        </div>
                        <div v-if="sections.length>0">
                            <section-row v-for="section in sections" :key="section.title" :section="section"></section-row>
                        </div>
                    </div>
                </div>
                <div style="width:5%;"></div>
            </div>
        </div>
    `,
};
