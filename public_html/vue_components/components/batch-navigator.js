/**
 * <batch-navigator> — minimal pagination control.
 *
 * Local re-implementation of the Magnus-tools component of the same name,
 * which isn't in vue_es6/. Same prop/event contract:
 *
 * Props:
 *   batch_size (Number) — items per page
 *   total      (Number) — total item count
 *   current    (Number) — current batch index (0-based)
 *
 * Emits:
 *   set-current — new batch index, when the user picks a page
 */

const { computed } = Vue;

export default {
    name: 'BatchNavigator',
    props: {
        batch_size: { type: [Number, String], default: 50 },
        total: { type: [Number, String], default: 0 },
        current: { type: [Number, String], default: 0 },
    },
    setup(props, { emit }) {
        const total_pages = computed(() => {
            const t = parseInt(props.total, 10) || 0;
            const b = parseInt(props.batch_size, 10) || 1;
            return Math.max(1, Math.ceil(t / b));
        });
        const current_page = computed(() => parseInt(props.current, 10) || 0);

        function go(delta) {
            const next = Math.min(
                Math.max(current_page.value + delta, 0),
                total_pages.value - 1,
            );
            if (next !== current_page.value) emit('set-current', next);
        }

        function goTo(event) {
            const v = parseInt(event.target.value, 10);
            if (Number.isNaN(v)) return;
            const next = Math.min(Math.max(v - 1, 0), total_pages.value - 1);
            emit('set-current', next);
        }

        return { total_pages, current_page, go, goTo };
    },
    template: `
        <div class="btn-group" role="group" aria-label="pagination">
            <button type="button" class="btn btn-outline-secondary" @click="go(-1)" :disabled="current_page<=0">&laquo;</button>
            <span class="btn btn-outline-secondary" style="pointer-events:none;">
                <input type="number" min="1" :max="total_pages" :value="current_page+1" @change="goTo" style="width:5rem;background:transparent;color:inherit;border:none;text-align:right;pointer-events:auto;" />
                / {{total_pages}}
            </span>
            <button type="button" class="btn btn-outline-secondary" @click="go(1)" :disabled="current_page>=total_pages-1">&raquo;</button>
        </div>
    `,
};
