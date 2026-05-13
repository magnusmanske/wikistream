/**
 * <section-row> — horizontal scroll-strip (or multi-row grid) of <entry-thumb>s.
 *
 * Two prop shapes:
 *   - `section`: { title, title_key?, q?, prop?, total, entries }   (preferred)
 *   - `entries`: bare array of entries (used by search results)
 *
 * `nolink` suppresses the heading link, `multi_row` switches from horizontal
 * scroller to wrapped grid.
 */

import { ttMixin } from '../../resources/vue_es6/state.js';

export default {
    name: 'SectionRow',
    mixins: [ttMixin],
    props: ['section', 'nolink', 'multi_row', 'entries'],
    template: `
        <div class="section">
            <h3 v-if='typeof section!="undefined"' class="text-capitalize">
                <span v-if='typeof section.title_key!="undefined"' :tt="section.title_key"></span>
                <span v-else-if='nolink || typeof section.q=="undefined"'>{{section.title}}</span>
                <router-link v-else-if='typeof section.prop=="undefined"' :to="'/section/'+section.q">{{section.title}}</router-link>
                <router-link v-else :to="'/section/'+section.q+'/'+(section.prop||'')">{{section.title}}</router-link>
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
