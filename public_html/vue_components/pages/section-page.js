/**
 * <section-page> — full grid of entries for one section.
 */

import { ttMixin } from '../../resources/vue_es6/state.js';
import { useLog } from '../composables/useLog.js';

const { ref, onMounted } = Vue;

export default {
    name: 'SectionPage',
    mixins: [ttMixin],
    props: ['section_q', 'section_prop'],
    setup(props) {
        const loading = ref(true);
        const section = ref({});

        const { log } = useLog();

        onMounted(async () => {
            log('section_loaded', { q: props.section_q });

            let url = './api.php?action=get_section&max=all&q=' + encodeURIComponent(props.section_q);
            if (typeof props.section_prop !== 'undefined') {
                url += '&prop=' + encodeURIComponent(props.section_prop);
            }
            try {
                const res = await fetch(url);
                const j = await res.json();
                section.value = j.data || {};
            } finally {
                loading.value = false;
            }
        });

        return { loading, section };
    },
    template: `
        <div class="container-fluid">
            <page-header></page-header>
            <div class="row" style="width:100%;">
                <skeleton-row v-if="loading" :count="12"></skeleton-row>
                <div v-else>
                    <div>
                        <section-row :section="section" nolink="1" multi_row="1"></section-row>
                    </div>
                </div>
            </div>
        </div>
    `,
};
