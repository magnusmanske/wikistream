<?php

/**
 * Narrow port through which WikiStreamConfig* subclasses read item rows
 * back from the tool DB, without having to know they're talking to a
 * WikiStream.
 *
 * Motivation (audits/STATUS.md P1.1 / design.md A.3-b): config methods
 * used to take `&$ws` and call `$ws->get_item_view(...)` /
 * `$ws->get_item_view_count(...)`, forming an architectural cycle that
 * blocked any clean extraction of the read path out of the god class.
 * This interface is the smallest port that satisfies every config-side
 * use today (the WikiFlix `female_directors` queries plus the no-op
 * WikiVibes implementation).
 */
interface ItemViewReader
{
	/**
	 * Page of rows from a `vw_*` items view, optionally constrained by
	 * a section membership and/or an arbitrary `q IN (subquery)`.
	 *
	 * Returns a list of view rows (each already passed through
	 * `fix_item_image` so the SPA sees a single `image` value).
	 */
	public function get_item_view(
		string $view_name,
		int $num = 25,
		?int $section_q = null,
		?string $subquery = null,
		int $offset = 0,
	): array;

	/**
	 * Total row count for the same view + filter, used to drive
	 * pagination state independent of `$num` / `$offset`.
	 */
	public function get_item_view_count(
		string $view_name,
		?int $section_q = null,
		?string $subquery = null,
	): int;
}
