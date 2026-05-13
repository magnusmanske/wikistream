/**
 * Bootstrap for the Vue 2.7 + Composition API build.
 *
 * Loads the wikistream-specific components, registers shared Magnus-tools
 * components via the existing `vue_es6/index.js` barrel, initialises
 * shared state (WikiData + ToolTranslation), and mounts the app.
 *
 * Sibling `index.es6.html` loads this as a module.
 *
 * Migration note: this file replaces `public_html/vue.js` in the new build.
 * Once the new build is verified, the legacy `vue.js` + the `.html` files
 * under `vue_components/` can be deleted and `index.html` swapped for
 * `index.es6.html`.
 */

import { setWd } from '../resources/vue_es6/state.js';
import { initToolTranslate } from '../resources/vue_es6/tool-translate.js';
import { registerAll } from '../resources/vue_es6/index.js';

import PageHeader      from './components/page-header.js';
import EntryThumb      from './components/entry-thumb.js';
import PersonThumb     from './components/person-thumb.js';
import SectionRow      from './components/section-row.js';
import BatchNavigator  from './components/batch-navigator.js';
import { createRouter } from './router.js';

// 1. Initialise shared state.
//    `WikiData` and `window.config` come from the classic <script> tags
//    in index.es6.html (wikidata.js + config.js, both loaded before this).
setWd(new WikiData());
initToolTranslate(window.config?.misc?.toolname || undefined);

// 2. Register shared Magnus-tools components globally (wd-link, widar,
//    commons-thumbnail, mastodon-button, …) via the barrel.
registerAll(Vue);

// 3. Register wikistream-local components.
Vue.component('page-header',     PageHeader);
Vue.component('entry-thumb',     EntryThumb);
Vue.component('person-thumb',    PersonThumb);
Vue.component('section-row',     SectionRow);
Vue.component('batch-navigator', BatchNavigator);

// 4. Mount.
const router = createRouter();
new Vue({ router }).$mount('#app');
