/**
 * <section-row> — horizontal scroll-strip (or multi-row grid) of <entry-thumb>s.
 *
 * Two prop shapes:
 *   - `section`: { title, title_key?, q?, prop?, key?, total, entries }
 *   - `entries`: bare array of entries (used by search results)
 *
 * Heading link target:
 *   - section.q present       → /section/<q>[/<prop>]
 *   - section.key present     → /special/<key>            (pseudo-section)
 *   - neither / nolink="1"    → unlinked text
 *
 * `multi_row` switches from horizontal scroller to wrapped grid.
 */

import { ttMixin } from '../../resources/vue_es6/state.js';

export default {
    name: 'SectionRow',
    mixins: [ttMixin],
    props: ['section', 'nolink', 'multi_row', 'entries'],
    computed: {
        link_target() {
            if (this.nolink) return null;
            const s = this.section;
            if (!s) return null;
            if (typeof s.q !== 'undefined') {
                return typeof s.prop !== 'undefined'
                    ? '/section/' + s.q + '/' + (s.prop || '')
                    : '/section/' + s.q;
            }
            if (typeof s.key !== 'undefined' && s.key !== '') {
                return '/special/' + s.key;
            }
            return null;
        },
    },
    template: `
        <div class="section">
            <h3 v-if='typeof section!="undefined"' class="text-capitalize">
                <router-link v-if="link_target" :to="link_target">
                    <span v-if="section.title_key" :tt="section.title_key">{{section.title}}</span>
                    <template v-else>{{section.title}}</template>
                </router-link>
                <template v-else>
                    <span v-if="section.title_key" :tt="section.title_key">{{section.title}}</span>
                    <template v-else>{{section.title}}</template>
                </template>
                <span v-if="nolink" class="section-total-plain">{{section.total}}</span>
                <span v-else class="section-total">{{section.total}}</span>
            </h3>
            <div v-if='typeof section!="undefined"' :class='multi_row?"section-multi-row":"section-single-row"'>
                <entry-thumb v-for="entry in section.entries" :key="entry.q" :entry="entry"></entry-thumb>
            </div>
            <div v-else :class='multi_row?"section-multi-row":"section-single-row"'>
                <entry-thumb v-for="entry in entries" :key="entry.q" :entry="entry"></entry-thumb>
            </div>
        </div>
    `,
};
