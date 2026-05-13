/**
 * <you-page> — the logged-in user's favourites list.
 *
 * The user name is read from `state.widar.userinfo.name` (the <widar>
 * component populates `state.widar` on creation).
 */

import { state, ttMixin } from '../../resources/vue_es6/state.js';

const { ref, onMounted, computed } = Vue;

export default {
    name: 'YouPage',
    mixins: [ttMixin],
    setup() {
        const loading = ref(true);
        const entries = ref([]);

        const user_name = computed(() => state.widar?.userinfo?.name || '');

        onMounted(async () => {
            try {
                const res = await fetch('./api.php?action=get_your_list');
                const j = await res.json();
                entries.value = j.data || [];
            } finally {
                loading.value = false;
            }
        });

        return { loading, entries, user_name };
    },
    template: `
        <div class="container-fluid">
            <page-header></page-header>
            <div class="row" style="width:100%; display: block;">
                <div>
                    <h1>{{user_name}}</h1>
                </div>
                <div v-if="!loading">
                    <h2 tt="your_list"></h2>
                    <section-row :entries="entries" multi_row="1"></section-row>
                </div>
            </div>
        </div>
    `,
};
