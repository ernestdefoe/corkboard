/**
 * Widget answers in a module-level store, keyed by what they ask: two widgets asking the same thing
 * share one request, and a host that re-creates its widgets on every redraw doesn't refetch.
 * Answers are kept a minute.
 */
const store = new Map<string, { at: number; data: any; pending: Promise<any> | null }>();

export function fetchWidget(key: string, load: () => Promise<any>): { data: any; loading: boolean } {
  let entry = store.get(key);
  if (!entry || (!entry.pending && Date.now() - entry.at > 60000)) {
    const fresh: { at: number; data: any; pending: Promise<any> | null } = { at: Date.now(), data: entry?.data ?? null, pending: null };
    fresh.pending = load()
      .then((data) => (fresh.data = data))
      .catch(() => (fresh.data = fresh.data ?? { error: true }))
      .finally(() => {
        fresh.pending = null;
        fresh.at = Date.now();
        m.redraw();
      });
    store.set(key, fresh);
    entry = fresh;
  }
  return { data: entry.data, loading: !!entry.pending && !entry.data };
}

/** Something changed on a board: widgets ask again next time they're drawn. */
export function forgetWidgets(): void {
  store.clear();
}
