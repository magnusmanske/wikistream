# wikistream — Composition API migration

The Vue 2.7 + Composition API + ES-modules build lives here, in
`public_html/vue_components/` alongside the legacy `.html` component
files. The legacy Vue 2 Options-API build under `public_html/vue.js`
+ `public_html/vue_components/*.html` is **still the production entry**
(`index.html`) until the new build is verified in a browser.

## Status

**All component ports are complete.** Pending: a manual browser smoke test
against a deployed `config.json`, then the switchover.

| Component                | Status     | Target file                                    |
|--------------------------|------------|------------------------------------------------|
| `page-header.html`       | ✅ Ported  | `components/page-header.js`                    |
| `entry-thumb.html`       | ✅ Ported  | `components/entry-thumb.js`                    |
| `person-thumb.html`      | ✅ Ported  | `components/person-thumb.js`                   |
| `section-row.html`       | ✅ Ported  | `components/section-row.js`                    |
| `batch-navigator` (shared, not in `resources/vue_es6/`) | ✅ New local impl | `components/batch-navigator.js` |
| `main-page.html`         | ✅ Ported  | `pages/main-page.js`                           |
| `entry-page.html`        | ✅ Ported  | `pages/entry-page.js`                          |
| `play-page.html`         | ✅ Ported  | `pages/play-page.js`                           |
| `section-page.html`      | ✅ Ported  | `pages/section-page.js`                        |
| `sections-page.html`     | ✅ Ported  | `pages/sections-page.js`                       |
| `search-page.html`       | ✅ Ported  | `pages/search-page.js`                         |
| `person-page.html`       | ✅ Ported  | `pages/person-page.js`                         |
| `candidates-page.html`   | ✅ Ported  | `pages/candidates-page.js`                     |
| `year-page.html`         | ✅ Ported  | `pages/year-page.js`                           |
| `you-page.html`          | ✅ Ported  | `pages/you-page.js`                            |

## Layout

```
public_html/
├── styles.css                wikistream-specific CSS (loaded by index.es6.html)
└── vue_components/
    ├── MIGRATION.md          (this file)
    ├── main.js               app bootstrap (replaces public_html/vue.js)
    ├── router.js             route table
    ├── components/           cross-page widgets
    │   ├── batch-navigator.js
    │   ├── entry-thumb.js
    │   ├── page-header.js
    │   ├── person-thumb.js
    │   └── section-row.js
    ├── pages/                top-level routed pages
    │   ├── candidates-page.js
    │   ├── entry-page.js
    │   ├── main-page.js
    │   ├── person-page.js
    │   ├── play-page.js
    │   ├── search-page.js
    │   ├── section-page.js
    │   ├── sections-page.js
    │   ├── year-page.js
    │   └── you-page.js
    ├── composables/          reusable Composition API hooks
    │   ├── useFullscreen.js
    │   ├── useLog.js
    │   └── useWikipediaDescription.js
    └── *.html                legacy Options-API components (still used by index.html)
```

The shared Magnus-tools library is at `public_html/resources/vue_es6/`
(state, wd-link, widar, commons-thumbnail, …) and is imported by
relative path from the files in this directory.

## Architectural notes

### Composition API + Options API together

Several components mix `setup()` with an `Options-API methods` block.
This is intentional: methods that need `this.$router` (navigation,
form submission) are easier in Options API, while everything reactive
or async is in `setup()`. Vue 2.7 supports both; Vue 3 will too.

### Mixin: `ttMixin`

Every component that renders translated text adds `mixins: [ttMixin]`
(from `state.js`). The mixin calls `state.tt.updateInterface(this.$el)`
on `mounted` and `updated` — no need to call it manually.

### Replacing the old globals

| Legacy global         | New source                                            |
|-----------------------|-------------------------------------------------------|
| `wd`                  | `state.wd` (set in `main.js`)                         |
| `tt`                  | `state.tt` (set via `initToolTranslate` in `main.js`) |
| `widar`               | `state.widar` (the `<widar>` component sets itself)   |
| `config` (data only)  | `window.config` — still a classic `<script>`          |
| `wikipediaDescriptionMixin` | `useWikipediaDescription()` composable          |

### What replaced jQuery

The legacy code used `$(this.$el).find(...)` for DOM access and
`$.getJSON(... ?callback=?)` for cross-origin requests:

- DOM access → vanilla `element.closest()`, `element.querySelector()`,
  or `event.currentTarget`.
- Cross-origin requests → `fetch()` with `origin=*` on the Wikipedia
  API (`useWikipediaDescription.js`).
- jQuery is still loaded for the shared library (Bootstrap, plus the
  shared library itself). It's not used in any new wikistream code.

### `batch-navigator` — local replacement

The original was a Magnus-tools shared component that isn't in
`vue_es6/`. A minimal local replacement lives at
`components/batch-navigator.js` with the same prop/event contract
(`batch_size`, `total`, `current` + `set-current` event).

## Recipe to port a component (kept for reference)

### 1. Convert the script

**Before (Options API, global script):**

```js
let MyPage = Vue.extend({
  props: ['q'],
  data: () => ({ loading: true, item: null }),
  created() { this.load(); },
  mounted() { tt.updateInterface(this.$el); },
  methods: {
    load() { /* ... */ },
  },
  template: '#my-template',
});
```

**After (Composition API, ES module):**

```js
import { ttMixin } from '../../resources/vue_es6/state.js';
const { ref, onMounted } = Vue;

export default {
  name: 'MyPage',
  mixins: [ttMixin],
  props: ['q'],
  setup(props) {
    const loading = ref(true);
    const item = ref(null);
    function load() { /* ... */ }
    onMounted(load);    // replaces `created()` for async work
    return { loading, item };
  },
  template: `<div>...</div>`,
};
```

### 2. Wire it up

- **Routed page**: replace the matching import + route in `router.js`.
- **Widget**: register globally in `main.js`:
  `Vue.component('my-widget', MyWidget);`

### 3. Move CSS

Append the rules to `styles.css`, prefixed by a component class so they
don't leak across components.

## Verifying the new build

Without a local `config.json` the dev server can't fully boot. To verify
against a production-shape `config.json`:

```bash
php -S localhost:8000 -t public_html
# open http://localhost:8000/index.es6.html#/play/10/Becky_sharp_still.jpg
```

The legacy `index.html` is untouched and remains the production entry
during verification. Once the new build is confirmed working, swap by:

1. Renaming `index.es6.html` → `index.html` (overwriting the old).
2. Deleting `public_html/vue.js` and every `public_html/vue_components/*.html`
   file. The `.js` files, the three subdirectories (`components/`, `pages/`,
   `composables/`), `main.js`, `router.js`, `styles.css`, and this file stay.

## When you're ready for Vue 3

The Composition-API source files in this directory are already
forward-compatible. Migration to Vue 3 becomes mechanical:

| Vue 2.7                                  | Vue 3                                           |
|------------------------------------------|--------------------------------------------------|
| `Vue.observable(_raw)` in `state.js`    | `reactive(_raw)`                                 |
| `new Vue({ router }).$mount('#app')`    | `createApp(App).use(router).mount('#app')`       |
| `new VueRouter({ routes })`             | `createRouter({ history: createWebHashHistory(), routes })` |
| `Vue.component(name, def)`              | `app.component(name, def)`                       |
| Global `Vue` UMD                        | Named imports: `import { ref, computed, onMounted } from 'vue';` |
| `mixins: [ttMixin]`                     | Same syntax still works; or rewrite as a composable |

None of those changes touch component bodies — `setup()`, `ref`,
`computed`, `onMounted`, `onBeforeUnmount` are identical across Vue 2.7
and Vue 3.
