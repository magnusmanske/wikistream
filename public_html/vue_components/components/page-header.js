/**
 * <page-header> — header bar shown on every page.
 *
 * Reads totals from `window.config` (the generated `config.js`).
 * Uses `ttMixin` from the shared library so that `tt` attributes in the
 * template are translated on mount/update.
 *
 * Vue 2.7 Composition API. Forward-compatible with Vue 3 (same imports,
 * same `setup()` signature, same lifecycle hook names).
 */

import { ttMixin } from '../../resources/vue_es6/state.js';

const { ref } = Vue;

export default {
    name: 'PageHeader',
    mixins: [ttMixin],
    setup() {
        const search_query = ref('');

        // Defaults to 0 if config hasn't loaded yet — config.js is a classic
        // <script> in index.html so it loads synchronously before us.
        const cfg = window.config || {};
        const entry_total = cfg.entry_total ?? 0;
        const person_total = cfg.person_total ?? 0;
        const section_total = cfg.section_total ?? 0;
        const help_page = cfg.misc?.help_page ?? '#';

        function toggle_search_bar(event) {
            const header = event.currentTarget.closest('.page-header');
            if (!header) return;
            const form = header.querySelector('form');
            if (!form) return;
            form.style.display = form.style.display === 'none' || form.style.display === '' ? 'block' : 'none';
            const first = form.querySelector('input');
            if (first) first.focus();
        }

        return { search_query, entry_total, person_total, section_total, help_page, toggle_search_bar };
    },
    methods: {
        // Methods that need `this.$router` stay as Options-API methods —
        // accessing the router instance from setup() in Vue 2.7 requires
        // boilerplate, and this is a small enough surface.
        do_search() {
            this.$router.push('/search/' + this.search_query);
        },
    },
    template: `
        <div class="row page-header">
            <router-link to="/"><h1 style="color: red" tt="toolname"></h1></router-link>
            <a :href="help_page" style="color: red; margin-left: 0.5rem;">ⓘ</a>
            <div class="page-header-movies-total">
                {{entry_total}} <span tt="movies_total"></span>
                {{person_total}} <span tt="people_in"></span>
                <router-link to="/sections">
                    {{section_total}} <span tt="sections_total"></span>
                </router-link>.
                <router-link to="/year" tt="by_years"></router-link>.
                <router-link to="/candidates" tt="add_more"></router-link>!
            </div>
            <div style="flex-grow: 1;"></div>
            <form style="display: none;" @submit.prevent="do_search">
                <input class="form-control" style="display: inline; vertical-align: baseline; width: 15rem;" type="text" v-model="search_query" tt_title="enter_to_search" />
                <input type="submit" style="display: none;" />
            </form>
            <a href="#" @click.prevent="toggle_search_bar" style="font-size: 24pt; margin-left: 0.5rem;">
                🔍
            </a>
            <widar>
                <template #loggedin>
                    <router-link to="/you">
                        <span style="font-size:26pt; color: green;" tt_title="logged_in">
                            <img border="0" src="https://upload.wikimedia.org/wikipedia/commons/thumb/6/61/Emoji_u1f464.svg/180px-Emoji_u1f464.svg.png" width="32px" />
                        </span>
                    </router-link>
                </template>
                <template #loggedout>
                    <a href="/api.php?action=authorize" style="font-size:26pt;" tt_title="log_in">👤</a>
                </template>
            </widar>
        </div>
    `,
};
