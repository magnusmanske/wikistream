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

import { state, ttMixin } from '../../resources/vue_es6/state.js';
import { loadFavorites } from '../composables/useFavorites.js';

const { ref, watch } = Vue;

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

		// Mobile-only — toggles visibility of the form that is always
		// shown on wider viewports via CSS media queries.
		function toggle_search_bar(event) {
			const header = event.currentTarget.closest('.page-header');
			if (!header) return;
			const form = header.querySelector('.page-header-search');
			if (!form) return;
			form.classList.toggle('is-visible');
			if (form.classList.contains('is-visible')) {
				const first = form.querySelector('input');
				if (first) first.focus();
			}
		}

		// Once widar reports the user as logged in, fetch their favourites
		// so <entry-thumb> hearts render correctly across the app.
		watch(
			() => state.widar && state.widar.userinfo && state.widar.userinfo.name,
			(name) => { if (name) loadFavorites(); },
			{ immediate: true },
		);

		return { search_query, entry_total, person_total, section_total, help_page, toggle_search_bar };
	},
	methods: {
		// Methods that need `this.$router` stay as Options-API methods —
		// accessing the router instance from setup() in Vue 2.7 requires
		// boilerplate, and this is a small enough surface.
		do_search() {
			this.$router.push('/search/' + this.search_query);
		},
		async random_entry() {
			try {
				const res = await fetch('./api.php?action=get_random_entry');
				if (!res.ok) return;
				const j = await res.json();
				const q = j && j.data && j.data.q;
				if (q) this.$router.push('/entry/' + q);
			} catch (_) { /* silent — best-effort discovery */ }
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
            <form class="page-header-search" @submit.prevent="do_search">
                <input class="form-control" style="display: inline; vertical-align: baseline; width: 15rem;" type="text" v-model="search_query" tt_title="enter_to_search" />
                <input type="submit" style="display: none;" />
            </form>
            <a href="#" @click.prevent="random_entry" tt_title="random_entry" style="font-size: 22pt; margin-left: 0.5rem; color: white; text-decoration: none;">
                <i class="bi bi-shuffle"></i>
            </a>
            <a href="#" class="page-header-search-toggle" @click.prevent="toggle_search_bar" style="font-size: 24pt; margin-left: 0.5rem;">
                🔍
            </a>
            <widar>
                <template #loggedin>
                    <router-link to="/you">
                        <span style="font-size:26pt; color: green;" tt_title="logged_in">
                            <img border="0" decoding="async" src="https://upload.wikimedia.org/wikipedia/commons/thumb/6/61/Emoji_u1f464.svg/250px-Emoji_u1f464.svg.png" width="32px" />
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
