/**
 * <you-page> — the logged-in user's favourites list.
 *
 * The user name is read from `state.widar.userinfo.name` (the <widar>
 * component populates `state.widar` on creation).
 */

import { state, ttMixin } from '../../resources/vue_es6/state.js';
import { useFetch } from '../composables/useFetch.js';

const { ref, onMounted, computed } = Vue;

export default {
    name: 'YouPage',
    mixins: [ttMixin],
    setup() {
        const loading = ref(true);
        const entries = ref([]);
        const { error, run } = useFetch();

        const user_name = computed(() => state.widar?.userinfo?.name || '');

        onMounted(async () => {
            const j = await run('./api.php?action=get_your_list');
            if (j) entries.value = j.data || [];
            loading.value = false;
        });

        return { loading, entries, user_name, error };
    },
    template: `
        <div class="container-fluid">
            <page-header></page-header>
            <div class="row" style="width:100%; display: block;">
                <div>
                    <h1>{{user_name}}</h1>
                </div>
                <error-banner v-if="error" :error="error"></error-banner>
                <skeleton-row v-else-if="loading" :count="12"></skeleton-row>
                <div v-else>
                    <h2 tt="your_list"></h2>
                    <section-row :entries="entries" multi_row="1"></section-row>
                </div>
            </div>
        </div>
    `,
};
