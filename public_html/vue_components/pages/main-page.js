/**
 * <main-page> — landing page. Sections come from the generated config.js;
 * an optional "recently viewed" row is shown above them when the user
 * has visited entries in this browser before.
 */

import { ttMixin } from '../../resources/vue_es6/state.js';
import { useLog } from '../composables/useLog.js';
import { useRecentlyViewed } from '../composables/useRecentlyViewed.js';

const { ref, computed } = Vue;

export default {
    name: 'MainPage',
    mixins: [ttMixin],
    setup() {
        const sections = ref(window.config?.sections || []);

        const { items: recentlyViewed } = useRecentlyViewed();
        const recentSection = computed(() => ({
            key: 'recently_viewed',
            title_key: 'recently_viewed',
            title: 'Recently viewed',
            entries: recentlyViewed.value,
        }));
        const hasRecent = computed(() => recentlyViewed.value.length > 0);

        const { log } = useLog();
        log('main_page_loaded');

        return { sections, recentSection, hasRecent };
    },
    template: `
        <div class="container-fluid">
            <page-header></page-header>
            <div class="row">
                <div style="width:100%;">
                    <section-row v-if="hasRecent" :section="recentSection"></section-row>
                    <section-row v-for="section in sections" :key="section.title_key || section.title" :section="section"></section-row>
                </div>
            </div>
        </div>
    `,
};
