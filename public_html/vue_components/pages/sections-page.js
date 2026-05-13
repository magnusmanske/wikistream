/**
 * <sections-page> — index table of every section with its movie count.
 */

import { ttMixin } from '../../resources/vue_es6/state.js';

const { ref, onMounted } = Vue;

export default {
    name: 'SectionsPage',
    mixins: [ttMixin],
    setup() {
        const loading = ref(true);
        const sections = ref([]);

        onMounted(async () => {
            try {
                const res = await fetch('./api.php?action=get_all_sections');
                const j = await res.json();
                sections.value = j.data || [];
            } catch (_) {
                sections.value = [];
            } finally {
                loading.value = false;
            }
        });

        return { loading, sections };
    },
    template: `
        <div class="container-fluid">
            <page-header></page-header>
            <div class="row" style="width:100%;">
                <div v-if="loading" style="width: 100%;display: flex;">
                    <div style="flex-grow: 1;"></div>
                    <div style="width: 50%;">
                        <skeleton-table :rows="10" :cols="2"></skeleton-table>
                    </div>
                    <div style="flex-grow: 1;"></div>
                </div>
                <div v-else style="width: 100%;display: flex;">
                    <div style="flex-grow: 1;"></div>
                    <table class="table table-striped" style="width: 50%;">
                        <thead>
                            <tr>
                                <th tt="section"></th>
                                <th tt="movies_in_section"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in sections" :key="s.section_q+'-'+s.property">
                                <td>
                                    <router-link :to="'/section/'+s.section_q+'/'+s.property" class="text-capitalize">
                                        {{s.label}}
                                    </router-link>
                                </td>
                                <td>{{s.cnt}}</td>
                            </tr>
                        </tbody>
                    </table>
                    <div style="flex-grow: 1;"></div>
                </div>
            </div>
        </div>
    `,
};
