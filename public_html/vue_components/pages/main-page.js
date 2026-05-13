/**
 * <main-page> — landing page. Sections come from the generated config.js.
 */

import { ttMixin } from '../../resources/vue_es6/state.js';
import { useLog } from '../composables/useLog.js';

const { ref } = Vue;

export default {
    name: 'MainPage',
    mixins: [ttMixin],
    setup() {
        const sections = ref(window.config?.sections || []);

        const { log } = useLog();
        log('main_page_loaded');

        return { sections };
    },
    template: `
        <div class="container-fluid">
            <page-header></page-header>
            <div class="row">
                <div style="width:100%;">
                    <section-row v-for="section in sections" :key="section.title" :section="section"></section-row>
                </div>
            </div>
        </div>
    `,
};
