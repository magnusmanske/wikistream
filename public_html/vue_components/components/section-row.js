/**
 * <section-row> — horizontal scroll-strip (or multi-row grid) of <entry-thumb>s.
 *
 * Two prop shapes:
 *   - `section`: { title, title_key?, q?, prop?, key?, total, entries }
 *   - `entries`: bare array of entries (used by search results)
 *
 * Heading link target:
 *   - section.q present                       → /section/<q>[/<prop>]
 *   - section.q + link_prefix="/group/"       → /group/<q>           (override)
 *   - section.key present                     → /special/<key>       (pseudo-section)
 *   - neither / nolink="1"                    → unlinked text
 *
 * `multi_row` switches from horizontal scroller to wrapped grid.
 */

import { ttMixin } from '../../resources/vue_es6/state.js';

export default {
    name: 'SectionRow',
    mixins: [ttMixin],
    props: ['section', 'nolink', 'multi_row', 'entries', 'link_prefix', 'removable'],
    emits: ['purge', 'remove'],
    computed: {
        link_target() {
            if (this.nolink) return null;
            const s = this.section;
            if (!s) return null;
            if (typeof s.q !== 'undefined') {
                if (this.link_prefix) {
                    return this.link_prefix + s.q;
                }
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
    methods: {
        onPurgeClick() {
            this.$emit('purge');
        },
        onEntryRemove(q) {
            this.$emit('remove', q);
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
                <button
                    v-if="removable"
                    type="button"
                    class="section-purge"
                    @click.stop.prevent="onPurgeClick"
                    tt_title="purge_recently_viewed"
                    aria-label="Clear recently viewed"
                >
                    <i class="bi bi-trash"></i>
                </button>
            </h3>
            <div v-if='typeof section!="undefined"' :class='multi_row?"section-multi-row":"section-single-row"'>
                <entry-thumb v-for="entry in section.entries" :key="entry.q" :entry="entry" :removable="removable" @remove="onEntryRemove"></entry-thumb>
            </div>
            <div v-else :class='multi_row?"section-multi-row":"section-single-row"'>
                <entry-thumb v-for="entry in entries" :key="entry.q" :entry="entry" :removable="removable" @remove="onEntryRemove"></entry-thumb>
            </div>
        </div>
    `,
};
