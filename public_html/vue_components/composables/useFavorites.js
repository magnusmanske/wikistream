/**
 * useFavorites — shared reactive list of Q-ids the logged-in user has
 * starred. Components anywhere can query `isFavorite(q)` and call
 * `toggleFavorite(q)`; the heart icon updates everywhere instantly.
 *
 * `loadFavorites()` is idempotent — call it once after `<widar>` finishes
 * logging in (e.g. from page-header on the user's name appearing). It
 * fetches `/api.php?action=get_your_list` and populates the in-memory
 * cache.
 *
 * Optimistic updates on toggle; revert on server failure.
 */

import { state } from '../../resources/vue_es6/state.js';

const { ref } = Vue;

const favorites = ref([]); // Array<Number>
let _loaded = false;
let _loading = false;

export async function loadFavorites() {
    if (_loaded || _loading) return;
    if (!state.widar || !state.widar.userinfo || !state.widar.userinfo.name) return;
    _loading = true;
    try {
        const res = await fetch('./api.php?action=get_your_list');
        if (!res.ok) return;
        const j = await res.json();
        favorites.value = (j.data || [])
            .map((e) => parseInt(e.q, 10))
            .filter((n) => Number.isFinite(n));
        _loaded = true;
    } catch (_) {
        // best-effort — UI degrades to "not loaded" state silently
    } finally {
        _loading = false;
    }
}

export function isFavorite(q) {
    const n = parseInt(q, 10);
    return Number.isFinite(n) && favorites.value.includes(n);
}

function addLocal(n) {
    if (!favorites.value.includes(n)) favorites.value = [...favorites.value, n];
}
function removeLocal(n) {
    favorites.value = favorites.value.filter((x) => x !== n);
}

export async function toggleFavorite(q) {
    const numQ = parseInt(q, 10);
    if (!Number.isFinite(numQ)) return;
    const becomingFav = !favorites.value.includes(numQ);

    // Optimistic local update.
    if (becomingFav) addLocal(numQ);
    else removeLocal(numQ);

    try {
        const res = await fetch(
            `./api.php?action=set_user_item_list&q=${numQ}&state=${becomingFav ? 1 : 0}`,
        );
        const j = await res.json();
        if (j.status !== 'OK') {
            if (becomingFav) removeLocal(numQ);
            else addLocal(numQ);
        }
    } catch (_) {
        // Revert on network error.
        if (becomingFav) removeLocal(numQ);
        else addLocal(numQ);
    }
}

export function useFavorites() {
    return { favorites, isFavorite, toggleFavorite, loadFavorites };
}
