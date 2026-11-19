import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { boardUrl, call, cardList, errorText, t, tx, write, type SlimCard } from '../util';
import { PageStructure } from './core';
import { boardState, leaveBoard, sort, type BoardState, type Column } from './BoardState';
import { BoardDrag, type DropTarget } from './drag';
import { CardFace } from './CardFace';
import CardModal from './CardModal';
import ColumnModal from './ColumnModal';
import { head } from './board/head';
import { carriedKey, freeKey } from './board/keys';

export interface Filters {
  q: string;
  mine: boolean;
  label: number | null;
  due: '' | 'late' | 'week' | 'none';
  priority: '' | 'urgent' | 'high' | 'normal' | 'low';
  hideDone: boolean;
}

/**
 * A board: its columns side by side, cards in each, dragged by mouse, finger or keyboard.
 *
 * Keyboard: Tab reaches the cards; arrows move between them; Space picks a card up, arrows carry
 * it (← → a column, ↑ ↓ a place), Space drops it and Escape puts it back. Each step is read out
 * by a live region. Enter opens a card. On a phone the columns are a strip that swipes one column
 * at a time; the chips above jump to a column.
 */
export default class BoardPage extends Page {
  state!: BoardState;
  filters: Filters = { q: '', mine: false, label: null, due: '', priority: '', hideDone: false };
  drag: BoardDrag | null = null;
  dropTarget: DropTarget | null = null;
  liftedId: number | null = null;
  /** The keyboard has a card in the air. */
  picked: { id: number; columnId: number; position: number } | null = null;
  focusId: number | null = null;
  announce = '';
  adding: number | null = null;
  /** Phones: the filters fold away behind one button. */
  filtersOpen = false;
  addTitle = '';
  activeColumn = 0;
  private openedCard = 0;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.bodyClass = 'App--corkboard';
    const id = parseInt(String(m.route.param('id')), 10);
    this.state = boardState(id);
    const q = new URLSearchParams(window.location.search);
    this.filters = {
      q: q.get('q') || '',
      mine: q.get('mine') === '1',
      label: q.get('label') ? Number(q.get('label')) : null,
      due: (q.get('due') as Filters['due']) || '',
      priority: (q.get('priority') as Filters['priority']) || '',
      hideDone: q.get('hide') === '1',
    };
    this.state.load().then(() => {
      const data = this.state.data;
      if (data) {
        app.setTitle(data.board.title);
        app.history.push('corkboard.board', data.board.title);
        const card = Number(q.get('card'));
        if (card) this.openCard(card);
      }
    });
  }

  oncreate(vnode: Mithril.VnodeDOM) {
    super.oncreate(vnode);
    this.attachDrag(vnode.dom as HTMLElement);
  }

  onupdate(vnode: Mithril.VnodeDOM) {
    this.attachDrag(vnode.dom as HTMLElement);
    // Phones: keep the chip of the column in view as the columns swipe (without scrolling the page).
    const tabs = (vnode.dom as HTMLElement).querySelector('.CorkboardColumnTabs') as HTMLElement | null;
    const tab = tabs?.querySelector('.is-active') as HTMLElement | null;
    if (tabs && tab && tabs.scrollWidth > tabs.clientWidth) {
      if (tab.offsetLeft < tabs.scrollLeft) tabs.scrollLeft = tab.offsetLeft - 12;
      else if (tab.offsetLeft + tab.offsetWidth > tabs.scrollLeft + tabs.clientWidth)
        tabs.scrollLeft = tab.offsetLeft + tab.offsetWidth - tabs.clientWidth + 12;
    }
    if (this.focusId) {
      const el = (vnode.dom as HTMLElement).querySelector(`[data-ck-card="${this.focusId}"]`) as HTMLElement | null;
      if (el && document.activeElement !== el) {
        el.focus({ preventScroll: true });
        el.scrollIntoView({ block: 'nearest', inline: 'nearest' });
      }
      this.focusId = null;
    }
  }

  onremove(vnode: Mithril.VnodeDOM) {
    super.onremove(vnode);
    this.drag?.destroy();
    leaveBoard(this.state.id);
  }

  private attachDrag(dom: HTMLElement): void {
    if (this.drag || !this.state.data?.abilities.edit) return;
    const root = dom.querySelector('.CorkboardBoard') as HTMLElement | null;
    if (!root) return;
    this.drag = new BoardDrag(root, {
      onStart: (id) => {
        this.state.dragging = true;
        this.liftedId = id;
        if (this.state.data?.push)
          call('POST', `/boards/${this.state.id}/moving`, { cardId: id }, undefined, true).catch(() => {
            /* only a hint for teammates: a lost one changes nothing */
          });
        m.redraw();
      },
      onMove: (target) => {
        this.dropTarget = target;
        m.redraw();
      },
      onDrop: (id, target) => {
        this.dropTarget = null;
        this.liftedId = null;
        this.move(id, target.columnId!, target.afterId, true);
      },
      onCancel: () => {
        this.dropTarget = null;
        this.liftedId = null;
        this.state.dropped();
        m.redraw();
      },
    });
  }

  view() {
    const s = this.state;
    if (s.error) return this.missing();
    const data = s.data;
    if (!data) return <PageStructure className="CorkboardBoardPage" loading={true} />;

    return (
      <PageStructure className="CorkboardBoardPage">
        {head(this)}
        {s.stale && !s.dragging ? (
          <button className="CorkboardStale" onclick={() => s.load()}>
            {t('board.stale')}
          </button>
        ) : null}
        {this.columnTabs()}
        <div className="CorkboardBoard">
          <div className="CorkboardBoard-strip" data-ck-strip onscroll={(e: Event) => this.scrolled(e)}>
            {data.columns.map((column) => this.column(column))}
            {data.abilities.manage ? (
              <button className="CorkboardBoard-addColumn" onclick={() => app.modal.show(ColumnModal, { board: s })}>
                <Icon name="fas fa-plus" /> {t('board.add_column')}
              </button>
            ) : null}
          </div>
        </div>
        <span id="CorkboardKeysHelp" className="sr-only">
          {t(data.abilities.edit ? 'card.keys_help' : 'card.keys_help_view')}
        </span>
        <div className="sr-only" role="status" aria-live="polite">
          {this.announce}
        </div>
      </PageStructure>
    );
  }

  missing(): Mithril.Children {
    return (
      <PageStructure className="CorkboardBoardPage">
        <div className="CorkboardEmpty">
          <h2>{t(this.state.error === 404 ? 'board.not_found' : 'board.error')}</h2>
          <Link className="Button" href={app.route('corkboard.index')}>
            {t('board.back')}
          </Link>
        </div>
      </PageStructure>
    );
  }

  setFilter(key: keyof Filters, value: any): void {
    const f: any = this.filters;
    f[key] = key === 'label' ? (value ? Number(value) : null) : value;
    // In the address, so a filtered board can be shared; without a route change (that would redraw the page from scratch).
    const q = new URLSearchParams(window.location.search);
    const put = (name: string, v: string | null) => (v ? q.set(name, v) : q.delete(name));
    put('q', this.filters.q || null);
    put('mine', this.filters.mine ? '1' : null);
    put('label', this.filters.label ? String(this.filters.label) : null);
    put('due', this.filters.due || null);
    put('priority', this.filters.priority || null);
    put('hide', this.filters.hideDone ? '1' : null);
    const qs = q.toString();
    window.history.replaceState(window.history.state, '', window.location.pathname + (qs ? '?' + qs : ''));
  }

  matches(card: SlimCard): boolean {
    const f = this.filters;
    const me = app.session.user ? Number(app.session.user.id()) : 0;
    if (f.q && !card.title.toLowerCase().includes(f.q.toLowerCase())) return false;
    if (f.mine && !card.assigneeIds.includes(me) && card.ownerId !== me) return false;
    if (f.label && !card.labelIds.includes(f.label)) return false;
    if (f.priority && card.priority !== f.priority) return false;
    if (f.hideDone && card.completedAt) return false;
    if (f.due) {
      const at = card.dueAt ? new Date(card.dueAt).getTime() : null;
      if (f.due === 'none' && at !== null) return false;
      if (f.due === 'late' && (at === null || at >= Date.now() || card.completedAt)) return false;
      if (f.due === 'week' && (at === null || at > Date.now() + 7 * 86400000)) return false;
    }
    return true;
  }

  filtering(): boolean {
    const f = this.filters;
    return !!(f.q || f.mine || f.label || f.due || f.priority || f.hideDone);
  }

  columnTabs(): Mithril.Children {
    const data = this.state.data!;
    return (
      <div className="CorkboardColumnTabs" role="tablist" aria-label={tx('board.columns')}>
        {data.columns.map((c, i) => (
          <button
            role="tab"
            aria-selected={i === this.activeColumn ? 'true' : 'false'}
            className={classList('CorkboardColumnTabs-tab', i === this.activeColumn && 'is-active')}
            onclick={() => this.showColumn(i)}
          >
            {c.title} <span className="CorkboardColumnTabs-count">{this.countLabel(c)}</span>
          </button>
        ))}
      </div>
    );
  }

  showColumn(i: number): void {
    const el = document.querySelectorAll('.CorkboardColumn')[i] as HTMLElement | undefined;
    el?.scrollIntoView({ behavior: 'smooth', inline: 'start', block: 'nearest' });
    this.activeColumn = i;
  }

  scrolled(e: Event): void {
    const strip = e.target as HTMLElement;
    const first = strip.querySelector('.CorkboardColumn') as HTMLElement | null;
    if (!first) return;
    const i = Math.round(strip.scrollLeft / (first.offsetWidth + 10));
    if (i !== this.activeColumn) {
      this.activeColumn = i;
      m.redraw();
    }
    (e as any).redraw = false;
  }

  countLabel(column: Column): string {
    const n = this.state.columnCards(column.id).length + (column.more || 0);
    return column.wipLimit ? `${n} / ${column.wipLimit}` : String(n);
  }

  column(column: Column): Mithril.Children {
    const s = this.state;
    const data = s.data!;
    const cards = s.columnCards(column.id).filter((c) => c.id === this.picked?.id || this.matches(c));
    const target = this.dropTarget && this.dropTarget.columnId === column.id ? this.dropTarget : null;

    const list: Mithril.Children[] = [];
    if (target && target.afterId === null) list.push(<div className="CorkboardDrop" key="drop" />);
    for (const card of cards) {
      list.push(this.card(card, column));
      if (target && target.afterId === card.id) list.push(<div className="CorkboardDrop" key="drop" />);
    }

    return (
      <section
        className={classList('CorkboardColumn', column.isDone && 'CorkboardColumn--done', target && 'is-target')}
        data-ck-column={column.id}
        key={column.id}
        aria-label={column.title}
      >
        {this.columnHead(column)}
        <div className="CorkboardColumn-list" data-ck-list>
          {list}
          {cards.length === 0 && !target ? (
            <p className="CorkboardColumn-empty">{t(this.filtering() ? 'column.empty_filtered' : 'column.empty')}</p>
          ) : null}
          {column.more ? (
            <Button className="Button Button--link CorkboardColumn-more" onclick={() => this.more(column)}>
              {t('column.more', { count: column.more })}
            </Button>
          ) : null}
        </div>
        {data.abilities.edit ? this.composer(column) : null}
      </section>
    );
  }

  columnHead(column: Column): Mithril.Children {
    const s = this.state;
    const over = column.wipLimit && s.columnCards(column.id).length > column.wipLimit;
    const full = column.wipLimit && s.columnCards(column.id).length === column.wipLimit;
    return (
      <header className="CorkboardColumn-head" style={column.color ? { borderTopColor: column.color } : {}}>
        {column.isDone ? <Icon name="fas fa-check" className="CorkboardColumn-doneIcon" /> : null}
        <h2 className="CorkboardColumn-title">{column.title}</h2>
        <span
          className={classList('CorkboardColumn-count', over && 'is-over', full && 'is-full')}
          title={column.wipLimit ? tx('column.wip_help', { limit: column.wipLimit }) : undefined}
        >
          {this.countLabel(column)}
        </span>
        {s.data!.abilities.manage ? (
          <Button
            className="Button Button--icon Button--link CorkboardColumn-menu"
            icon="fas fa-ellipsis"
            aria-label={tx('column.edit', { title: column.title })}
            onclick={() => app.modal.show(ColumnModal, { board: s, column })}
          />
        ) : null}
      </header>
    );
  }

  card(card: SlimCard, column: Column): Mithril.Children {
    const s = this.state;
    const picked = this.picked?.id === card.id;
    const edit = s.data!.abilities.edit;
    return (
      <div
        key={card.id}
        className={classList(
          'CorkboardCard',
          card.completedAt && 'is-done',
          picked && 'is-picked',
          this.liftedId === card.id && 'CorkboardCard--lifted'
        )}
        data-ck-card={card.id}
        tabindex="0"
        role="button"
        aria-roledescription={tx(edit ? 'card.role_draggable' : 'card.role')}
        aria-describedby="CorkboardKeysHelp"
        onclick={() => !picked && this.openCard(card.id)}
        onkeydown={(e: KeyboardEvent) => {
          this.key(e, card, column);
        }}
      >
        <CardFace card={card} data={s.data!} moving={s.movingOn(card.id)} />
      </div>
    );
  }

  composer(column: Column): Mithril.Children {
    if (this.state.data!.board.status !== 'active') return null;
    if (this.adding !== column.id) {
      return (
        <button
          className="CorkboardColumn-add"
          onclick={() => {
            this.adding = column.id;
            this.addTitle = '';
          }}
        >
          <Icon name="fas fa-plus" /> {t('column.add_card')}
        </button>
      );
    }
    return this.composerForm(column);
  }

  composerForm(column: Column): Mithril.Children {
    const save = () => {
      const title = this.addTitle.trim();
      if (!title) return;
      this.addTitle = '';
      write('POST', 'cards', null, { boardId: this.state.id, columnId: column.id, title }).then((answer) => {
        this.state.card(answer);
        this.announce = tx('board.added', { title });
      });
    };
    return (
      <div className="CorkboardColumn-composer">
        <textarea
          className="FormControl"
          rows={2}
          maxlength={160}
          aria-label={tx('column.add_card')}
          placeholder={tx('column.card_placeholder')}
          value={this.addTitle}
          oncreate={(v: Mithril.VnodeDOM) => (v.dom as HTMLTextAreaElement).focus()}
          oninput={(e: Event) => (this.addTitle = (e.target as HTMLTextAreaElement).value)}
          onkeydown={(e: KeyboardEvent) => {
            if (e.key === 'Enter' && !e.shiftKey) {
              e.preventDefault();
              save();
            }
            if (e.key === 'Escape') this.adding = null;
          }}
        />
        <div className="CorkboardColumn-composerActions">
          <Button className="Button Button--primary" onclick={save}>
            {t('column.add')}
          </Button>
          <Button className="Button Button--link" onclick={() => (this.adding = null)}>
            {t('cancel')}
          </Button>
        </div>
      </div>
    );
  }

  /** Arrow keys move focus; Space lifts and drops; with a card in the air, arrows carry it. */
  key(e: KeyboardEvent, card: SlimCard, column: Column): void {
    const s = this.state;
    const columns = s.data!.columns;
    const ci = columns.findIndex((c) => c.id === column.id);
    const list = s.columnCards(column.id).filter((c) => c.id === this.picked?.id || this.matches(c));
    const at = { columns, ci, list, i: list.findIndex((c) => c.id === card.id) };
    if (this.picked && this.picked.id === card.id) carriedKey(this, e, card, column, at);
    else freeKey(this, e, card, column, at);
  }

  /** Put the card in the air somewhere else (locally; saved on the drop). */
  carry(card: SlimCard, to: Column, afterId: number | null): void {
    const data = this.state.data!;
    card.columnId = to.id;
    card.position = this.between(to.id, afterId, card.id);
    data.cards = sort(data.cards);
    const list = this.state.columnCards(to.id).filter((c) => c.id === card.id || this.matches(c));
    const ci = data.columns.findIndex((c) => c.id === to.id);
    this.announce = tx('keys.moved', {
      column: to.title,
      col: ci + 1,
      cols: data.columns.length,
      position: list.findIndex((c) => c.id === card.id) + 1,
      total: list.length,
    });
    this.focusId = card.id;
  }

  /** A local position between the card after which it lands and the next one. */
  between(columnId: number, afterId: number | null, except: number): number {
    const list = this.state.columnCards(columnId).filter((c) => c.id !== except);
    if (!list.length) return 0;
    if (afterId === null) return list[0].position - 1024;
    const i = list.findIndex((c) => c.id === afterId);
    const prev = list[i].position;
    const next = list[i + 1]?.position;
    return next === undefined ? prev + 1024 : (prev + next) / 2;
  }

  afterOf(card: SlimCard): number | null {
    const list = this.state.columnCards(card.columnId);
    const i = list.findIndex((c) => c.id === card.id);
    return i > 0 ? list[i - 1].id : null;
  }

  /** Save a move: optimistic, and back where it was if the server says no. */
  move(id: number, columnId: number, afterId: number | null, local: boolean): void {
    const s = this.state;
    const data = s.data!;
    const card = data.cards.find((c) => c.id === id);
    if (!card) return;
    if (local) {
      if (afterId === id) return s.dropped();
      const same = card.columnId === columnId && this.afterOf(card) === afterId;
      if (same) return s.dropped();
      card.columnId = columnId;
      card.position = this.between(columnId, afterId, id);
      data.cards = sort(data.cards);
      const column = data.columns.find((c) => c.id === columnId);
      if (column) this.announce = tx('keys.dropped_short', { title: card.title, column: column.title });
    }
    m.redraw();
    write('PATCH', 'cards', id, { columnId, afterId }, true)
      .then((answer) => {
        s.card(answer);
        s.dropped();
      })
      .catch((error) => {
        app.alerts.show({ type: 'error' }, tx('board.move_failed'));
        s.dragging = false;
        s.load();
        throw error;
      });
  }

  more(column: Column): void {
    const last = this.state.columnCards(column.id).slice(-1)[0];
    cardList({ column: column.id, after: last?.position }, 150).then(
      (answer) => {
        const data = this.state.data!;
        const known = new Set(data.cards.map((c) => c.id));
        data.cards = sort([...data.cards, ...answer.cards.filter((c: SlimCard) => !known.has(c.id))]);
        Object.assign(data.users, answer.users);
        column.more = Math.max(0, column.more - answer.cards.length);
        m.redraw();
      },
      (error) => app.alerts.show({ type: 'error' }, errorText(error))
    );
  }

  openCard(id: number): void {
    if (this.openedCard === id && app.modal.modal) return;
    this.openedCard = id;
    const url = new URL(window.location.href);
    url.searchParams.set('card', String(id));
    window.history.replaceState(window.history.state, '', url.pathname + url.search);
    app.modal.show(CardModal, {
      board: this.state,
      cardId: id,
      onclose: () => {
        this.openedCard = 0;
        const u = new URL(window.location.href);
        u.searchParams.delete('card');
        window.history.replaceState(window.history.state, '', u.pathname + u.search);
        this.focusId = id;
        m.redraw();
      },
    });
  }

  pin(): void {
    const data = this.state.data!;
    data.pinned = !data.pinned;
    call('POST', `/boards/${this.state.id}/pin`, { pinned: data.pinned });
  }

  reopen(): void {
    write('PATCH', 'boards', this.state.id, { status: 'active' }).then((answer) => this.state.replace(answer));
  }
}

export { boardUrl };
