/**
 * <section-page> — full grid of entries for one section.
 */

import { ttMixin } from '../../resources/vue_es6/state.js';
import { useLog } from '../composables/useLog.js';
import { useFetch } from '../composables/useFetch.js';

const { ref, onMounted } = Vue;

export default {
    name: 'SectionPage',
    mixins: [ttMixin],
    props: ['section_q', 'section_prop'],
    setup(props) {
        const loading = ref(true);
        const section = ref({});

        const { log } = useLog();
        const { error, run } = useFetch();

        onMounted(async () => {
            log('section_loaded', { q: props.section_q });

            let url = './api.php?action=get_section&max=all&q=' + encodeURIComponent(props.section_q);
            if (typeof props.section_prop !== 'undefined') {
                url += '&prop=' + encodeURIComponent(props.section_prop);
            }
            const j = await run(url);
            if (j) section.value = j.data || {};
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
                    <div>
                        <section-row :section="section" nolink="1" multi_row="1"></section-row>
                    </div>
                </div>
            </div>
        </div>
    `,
};
