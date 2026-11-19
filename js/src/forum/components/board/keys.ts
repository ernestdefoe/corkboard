import { tx, type SlimCard } from '../../util';
import type BoardPage from '../BoardPage';
import { sort, type Column } from '../BoardState';

/** Where the focused card stands: its column's index and the cards shown with it. */
export interface KeyPlace {
  columns: Column[];
  ci: number;
  list: SlimCard[];
  i: number;
}

/** With this card in the air: ↑ ↓ carry it a place, ← → a column, Space/Enter drop it, Escape/Tab put it back. */
export function carriedKey(page: BoardPage, e: KeyboardEvent, card: SlimCard, column: Column, at: KeyPlace): void {
  const s = page.state;
  const { columns, ci, list, i } = at;
  if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
    e.preventDefault();
    const j = i + (e.key === 'ArrowUp' ? -1 : 1);
    if (j < 0 || j >= list.length) return;
    page.carry(card, column, j > i ? list[j].id : j > 0 ? list[j - 1].id : null);
  } else if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
    e.preventDefault();
    const to = columns[ci + (e.key === 'ArrowLeft' ? -1 : 1)];
    if (!to) return;
    const there = s.columnCards(to.id).filter((c) => page.matches(c));
    const pos = Math.min(i, there.length);
    page.carry(card, to, pos > 0 ? there[pos - 1].id : null);
  } else if (e.key === ' ' || e.key === 'Enter') {
    e.preventDefault();
    const picked = page.picked!;
    page.picked = null;
    const after = page.afterOf(card);
    page.announce = tx('keys.dropped', { title: card.title, column: column.title, position: i + 1, total: list.length });
    if (card.columnId !== picked.columnId || card.position !== picked.position) page.move(card.id, card.columnId, after, false);
    else s.dropped();
    page.focusId = card.id;
  } else if (e.key === 'Escape' || e.key === 'Tab') {
    putBack(page, e, card);
  }
}

function putBack(page: BoardPage, e: KeyboardEvent, card: SlimCard): void {
  const s = page.state;
  const data = s.data!;
  if (e.key === 'Escape') e.preventDefault();
  card.columnId = page.picked!.columnId;
  card.position = page.picked!.position;
  data.cards = sort(data.cards);
  page.picked = null;
  s.dropped();
  page.announce = tx('keys.cancelled', { title: card.title });
  page.focusId = card.id;
}

/** No card in the air: Space lifts this one, Enter opens it, arrows move the focus. */
export function freeKey(page: BoardPage, e: KeyboardEvent, card: SlimCard, column: Column, at: KeyPlace): void {
  const s = page.state;
  const data = s.data!;
  const { columns, ci, list, i } = at;
  if (e.key === ' ' && data.abilities.edit && data.board.status === 'active') {
    e.preventDefault();
    page.picked = { id: card.id, columnId: card.columnId, position: card.position };
    s.dragging = true;
    page.announce = tx('keys.picked', {
      title: card.title,
      column: column.title,
      col: ci + 1,
      cols: columns.length,
      position: i + 1,
      total: list.length,
    });
    page.focusId = card.id;
  } else if (e.key === 'Enter') {
    e.preventDefault();
    page.openCard(card.id);
  } else if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
    e.preventDefault();
    const next = list[i + (e.key === 'ArrowUp' ? -1 : 1)];
    if (next) page.focusId = next.id;
  } else if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
    e.preventDefault();
    for (let k = ci + (e.key === 'ArrowLeft' ? -1 : 1); k >= 0 && k < columns.length; k += e.key === 'ArrowLeft' ? -1 : 1) {
      const there = s.columnCards(columns[k].id).filter((c) => page.matches(c));
      if (there.length) {
        page.focusId = there[Math.min(i, there.length - 1)].id;
        break;
      }
    }
  }
}
