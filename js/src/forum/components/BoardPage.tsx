import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { Avatar, boardUrl, call, t, tx, VISIBILITY_ICON, type SlimCard } from '../util';
import { PageStructure } from './core';
import { boardState, leaveBoard, sort, type BoardState, type Column } from './BoardState';
import { BoardDrag, type DropTarget } from './drag';
import { CardFace } from './CardFace';
import CardModal from './CardModal';
import ColumnModal from './ColumnModal';
import ActivityModal from './ActivityModal';

interface Filters {
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
        if (this.state.data?.push) call('POST', `/boards/${this.state.id}/moving`, { cardId: id }, undefined, true).catch(() => {});
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
    const b = data.board;

    return (
      <PageStructure className="CorkboardBoardPage">
        <div className="CorkboardBoardPage-head" style={{ '--board-color': b.color }}>
          <div className="CorkboardBoardPage-crumbs">
            <Link href={app.route('corkboard.index')}>{t('nav')}</Link>
            {b.project ? [' / ', <Link href={app.route('projects.show', { slug: b.project.slug })}>{b.project.title}</Link>] : null}
          </div>
          <div className="CorkboardBoardPage-titleRow">
            <h1 className="CorkboardBoardPage-title">{b.title}</h1>
            <span className={'CorkboardChip CorkboardChip--' + b.visibility}>
              <Icon name={VISIBILITY_ICON[b.visibility]} />
              {t('visibility.' + b.visibility)}
            </span>
            {s.live ? (
              <span className="CorkboardLive" title={tx('board.live_help')}>
                <span className="CorkboardLive-dot" />
                {t('board.live')}
              </span>
            ) : null}
            <span className="CorkboardBoardPage-spacer" />
            {this.team()}
            {app.session.user ? (
              <Button
                className="Button Button--icon"
                icon={data.pinned ? 'fas fa-thumbtack' : 'fas fa-thumbtack'}
                aria-label={tx(data.pinned ? 'board.unpin' : 'board.pin')}
                aria-pressed={data.pinned ? 'true' : 'false'}
                onclick={() => this.pin()}
              />
            ) : null}
            <Button className="Button" icon="fas fa-clock-rotate-left" onclick={() => app.modal.show(ActivityModal, { board: s })}>
              {t('board.activity')}
            </Button>
            {data.abilities.manage || data.abilities.reopen ? (
              <Link className="Button" href={app.route('corkboard.settings', { id: `${b.id}-${b.slug}` })}>
                <Icon name="fas fa-gear" className="Button-icon" />
                <span className="Button-label">{t('board.settings')}</span>
              </Link>
            ) : null}
          </div>
          {b.description ? <p className="CorkboardBoardPage-description">{b.description}</p> : null}
          {b.status !== 'active' ? this.closedBanner() : null}
          {this.filterBar()}
        </div>
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

  team(): Mithril.Children {
    const data = this.state.data!;
    const ids = [data.board.ownerId, ...data.members.filter((m) => m.userId).map((m) => m.userId)].filter((id, i, a) => id && a.indexOf(id) === i) as number[];
    const groups = data.members.filter((m) => m.groupId).map((m) => data.groups[m.groupId!]).filter(Boolean);
    if (!ids.length && !groups.length) return null;
    return (
      <div className="CorkboardTeam" aria-label={tx('board.team')}>
        {ids.slice(0, 6).map((id) => (
          <Avatar user={data.users[id]} size={30} ring />
        ))}
        {ids.length > 6 ? <span className="CorkboardTeam-more">+{ids.length - 6}</span> : null}
        {groups.length ? <span className="CorkboardTeam-groups">+ {groups.map((g) => g.name).join(', ')}</span> : null}
      </div>
    );
  }

  closedBanner(): Mithril.Children {
    const data = this.state.data!;
    const b = data.board;
    const when = b.status === 'completed' ? b.completedAt : b.archivedAt;
    return (
      <div className={'CorkboardClosed CorkboardClosed--' + b.status}>
        <Icon name={b.status === 'completed' ? 'fas fa-flag-checkered' : 'fas fa-box-archive'} />
        <span>{t('board.closed_' + b.status, { date: when ? new Date(when).toLocaleDateString() : '' })}</span>
        {data.abilities.reopen ? (
          <Button className="Button Button--primary" onclick={() => this.reopen()}>
            {t('board.reopen')}
          </Button>
        ) : null}
      </div>
    );
  }

  filterBar(): Mithril.Children {
    const data = this.state.data!;
    const f = this.filters;
    const done = data.board.doneCount;
    const late = data.cards.filter((c) => !c.completedAt && c.dueAt && new Date(c.dueAt).getTime() < Date.now()).length;
    // Core's Select markup, so it gets the theme's caret.
    const select = (key: keyof Filters, options: Array<[string, any]>, label: string) => (
      <span className={classList('Select CorkboardFilters-select', f[key] ? 'is-on' : '')}>
        <select className="Select-input FormControl" aria-label={label} value={String(f[key] ?? '')} onchange={(e: Event) => this.setFilter(key, (e.target as HTMLSelectElement).value)}>
          {options.map(([value, text]) => (
            <option value={value}>{text}</option>
          ))}
        </select>
        <Icon name="fas fa-sort" className="Select-caret" />
      </span>
    );
    return (
      <div className={classList('CorkboardFilters', this.filtersOpen && 'is-open')}>
        <button className={classList('CorkboardToggle CorkboardFilters-toggle', this.filtering() && 'is-on')} aria-expanded={this.filtersOpen ? 'true' : 'false'} onclick={() => (this.filtersOpen = !this.filtersOpen)}>
          <Icon name="fas fa-filter" /> {t('board.filters')}
        </button>
        <input
          className="FormControl CorkboardFilters-search"
          type="search"
          placeholder={tx('board.filter_placeholder')}
          aria-label={tx('board.filter_placeholder')}
          value={f.q}
          oninput={(e: Event) => this.setFilter('q', (e.target as HTMLInputElement).value)}
        />
        {app.session.user ? (
          <button className={classList('CorkboardToggle', f.mine && 'is-on')} aria-pressed={f.mine ? 'true' : 'false'} onclick={() => this.setFilter('mine', !f.mine)}>
            {t('board.filter_mine')}
          </button>
        ) : null}
        {data.labels.length
          ? select('label', [['', tx('board.filter_labels')], ...data.labels.map((l) => [String(l.id), l.name || tx('label.unnamed')] as [string, string])], tx('board.filter_labels'))
          : null}
        {select(
          'due',
          [
            ['', tx('board.filter_due')],
            ['late', tx('board.due_late')],
            ['week', tx('board.due_week')],
            ['none', tx('board.due_none')],
          ],
          tx('board.filter_due')
        )}
        {select(
          'priority',
          [['', tx('board.filter_priority')], ...['urgent', 'high', 'normal', 'low'].map((p) => [p, tx('priority.' + p)] as [string, string])],
          tx('board.filter_priority')
        )}
        <button className={classList('CorkboardToggle', f.hideDone && 'is-on')} aria-pressed={f.hideDone ? 'true' : 'false'} onclick={() => this.setFilter('hideDone', !f.hideDone)}>
          {t('board.filter_hide_done')}
        </button>
        <span className="CorkboardFilters-summary">{t('board.summary', { done, total: data.board.cardCount, late })}</span>
      </div>
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
    const over = column.wipLimit && s.columnCards(column.id).length > column.wipLimit;
    const full = column.wipLimit && s.columnCards(column.id).length === column.wipLimit;
    const target = this.dropTarget && this.dropTarget.columnId === column.id ? this.dropTarget : null;

    const list: Mithril.Children[] = [];
    if (target && target.afterId === null) list.push(<div className="CorkboardDrop" key="drop" />);
    for (const card of cards) {
      list.push(this.card(card, column));
      if (target && target.afterId === card.id) list.push(<div className="CorkboardDrop" key="drop" />);
    }

    return (
      <section className={classList('CorkboardColumn', column.isDone && 'CorkboardColumn--done', target && 'is-target')} data-ck-column={column.id} key={column.id} aria-label={column.title}>
        <header className="CorkboardColumn-head" style={column.color ? { borderTopColor: column.color } : {}}>
          {column.isDone ? <Icon name="fas fa-check" className="CorkboardColumn-doneIcon" /> : null}
          <h2 className="CorkboardColumn-title">{column.title}</h2>
          <span className={classList('CorkboardColumn-count', over && 'is-over', full && 'is-full')} title={column.wipLimit ? tx('column.wip_help', { limit: column.wipLimit }) : undefined}>
            {this.countLabel(column)}
          </span>
          {data.abilities.manage ? (
            <Button className="Button Button--icon Button--link CorkboardColumn-menu" icon="fas fa-ellipsis" aria-label={tx('column.edit', { title: column.title })} onclick={() => app.modal.show(ColumnModal, { board: s, column })} />
          ) : null}
        </header>
        <div className="CorkboardColumn-list" data-ck-list>
          {list}
          {cards.length === 0 && !target ? <p className="CorkboardColumn-empty">{t(this.filtering() ? 'column.empty_filtered' : 'column.empty')}</p> : null}
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

  card(card: SlimCard, column: Column): Mithril.Children {
    const s = this.state;
    const picked = this.picked?.id === card.id;
    const edit = s.data!.abilities.edit;
    return (
      <div
        key={card.id}
        className={classList('CorkboardCard', card.completedAt && 'is-done', picked && 'is-picked', this.liftedId === card.id && 'CorkboardCard--lifted')}
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
    const save = () => {
      const title = this.addTitle.trim();
      if (!title) return;
      this.addTitle = '';
      call('POST', `/boards/${this.state.id}/cards`, { columnId: column.id, title }).then((answer) => {
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
    const data = s.data!;
    const columns = data.columns;
    const ci = columns.findIndex((c) => c.id === column.id);
    const list = s.columnCards(column.id).filter((c) => c.id === this.picked?.id || this.matches(c));
    const i = list.findIndex((c) => c.id === card.id);

    if (this.picked && this.picked.id === card.id) {
      if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
        e.preventDefault();
        const j = i + (e.key === 'ArrowUp' ? -1 : 1);
        if (j < 0 || j >= list.length) return;
        this.carry(card, column, j > i ? list[j].id : j > 0 ? list[j - 1].id : null);
      } else if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
        e.preventDefault();
        const to = columns[ci + (e.key === 'ArrowLeft' ? -1 : 1)];
        if (!to) return;
        const there = s.columnCards(to.id).filter((c) => this.matches(c));
        const at = Math.min(i, there.length);
        this.carry(card, to, at > 0 ? there[at - 1].id : null);
      } else if (e.key === ' ' || e.key === 'Enter') {
        e.preventDefault();
        const picked = this.picked;
        this.picked = null;
        const after = this.afterOf(card);
        this.announce = tx('keys.dropped', { title: card.title, column: column.title, position: i + 1, total: list.length });
        if (card.columnId !== picked.columnId || card.position !== picked.position) this.move(card.id, card.columnId, after, false);
        else s.dropped();
        this.focusId = card.id;
      } else if (e.key === 'Escape' || e.key === 'Tab') {
        if (e.key === 'Escape') e.preventDefault();
        card.columnId = this.picked.columnId;
        card.position = this.picked.position;
        data.cards = sort(data.cards);
        this.picked = null;
        s.dropped();
        this.announce = tx('keys.cancelled', { title: card.title });
        this.focusId = card.id;
      }
      return;
    }

    if (e.key === ' ' && data.abilities.edit && data.board.status === 'active') {
      e.preventDefault();
      this.picked = { id: card.id, columnId: card.columnId, position: card.position };
      s.dragging = true;
      this.announce = tx('keys.picked', { title: card.title, column: column.title, col: ci + 1, cols: columns.length, position: i + 1, total: list.length });
      this.focusId = card.id;
    } else if (e.key === 'Enter') {
      e.preventDefault();
      this.openCard(card.id);
    } else if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
      e.preventDefault();
      const next = list[i + (e.key === 'ArrowUp' ? -1 : 1)];
      if (next) this.focusId = next.id;
    } else if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
      e.preventDefault();
      for (let k = ci + (e.key === 'ArrowLeft' ? -1 : 1); k >= 0 && k < columns.length; k += e.key === 'ArrowLeft' ? -1 : 1) {
        const there = s.columnCards(columns[k].id).filter((c) => this.matches(c));
        if (there.length) {
          this.focusId = there[Math.min(i, there.length - 1)].id;
          break;
        }
      }
    }
  }

  /** Put the card in the air somewhere else (locally; saved on the drop). */
  carry(card: SlimCard, to: Column, afterId: number | null): void {
    const data = this.state.data!;
    card.columnId = to.id;
    card.position = this.between(to.id, afterId, card.id);
    data.cards = sort(data.cards);
    const list = this.state.columnCards(to.id).filter((c) => c.id === card.id || this.matches(c));
    const ci = data.columns.findIndex((c) => c.id === to.id);
    this.announce = tx('keys.moved', { column: to.title, col: ci + 1, cols: data.columns.length, position: list.findIndex((c) => c.id === card.id) + 1, total: list.length });
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
    call('PATCH', `/cards/${id}`, { columnId, afterId }, undefined, true)
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
    call('GET', `/boards/${this.state.id}/cards`, undefined, { column: column.id, after: last?.position }).then((answer) => {
      const data = this.state.data!;
      const known = new Set(data.cards.map((c) => c.id));
      data.cards = sort([...data.cards, ...answer.cards.filter((c: SlimCard) => !known.has(c.id))]);
      Object.assign(data.users, answer.users);
      column.more = Math.max(0, column.more - answer.cards.length);
      m.redraw();
    });
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
    call('PATCH', `/boards/${this.state.id}`, { status: 'active' }).then((answer) => this.state.replace(answer));
  }
}

export { boardUrl };
