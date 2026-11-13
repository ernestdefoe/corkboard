import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { boardUrl, call, due, t, tx } from '../util';
import { IndexSidebar, PageStructure } from './core';
import { PRIORITY_CLASS } from './CardFace';

export interface ListCard {
  id: number;
  title: string;
  priority: string;
  dueAt: string | null;
  dueAllDay: boolean;
  completedAt: string | null;
  boardId: number;
  board: string;
  boardSlug: string;
  boardColor: string;
  column: string;
}

/** /corkboard/mine: every card the viewer is on, by board, and their private calendar link. */
export default class MyCardsPage extends Page {
  cards: ListCard[] = [];
  total = 0;
  loading = true;
  owned = false;
  done = false;
  feed: string | null = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.bodyClass = 'App--corkboard';
    app.setTitle(tx('mine.title'));
    app.history.push('corkboard.mine', tx('mine.title'));
    this.load();
  }

  load(): void {
    this.loading = true;
    call('GET', '/my-cards', undefined, { limit: 100, owned: this.owned ? 1 : undefined, done: this.done ? 1 : undefined }, true)
      .then((answer) => {
        this.cards = answer.cards;
        this.total = answer.total;
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    const groups = new Map<number, ListCard[]>();
    this.cards.forEach((c) => groups.set(c.boardId, [...(groups.get(c.boardId) || []), c]));
    return (
      <PageStructure className="CorkboardMyCardsPage" sidebar={() => <IndexSidebar />}>
        <div className="CorkboardBoardsPage-head">
          <h1 className="CorkboardBoardsPage-title">{t('mine.title')}</h1>
        </div>
        {!app.session.user ? (
          <p className="CorkboardEmpty">{t('mine.guest')}</p>
        ) : (
          [
            <div className="CorkboardFilters">
              <button className={classList('CorkboardToggle', this.owned && 'is-on')} aria-pressed={this.owned ? 'true' : 'false'} onclick={() => ((this.owned = !this.owned), this.load())}>
                {t('mine.owned')}
              </button>
              <button className={classList('CorkboardToggle', this.done && 'is-on')} aria-pressed={this.done ? 'true' : 'false'} onclick={() => ((this.done = !this.done), this.load())}>
                {t('mine.done')}
              </button>
              <span className="CorkboardFilters-summary">{t('mine.count', { count: this.total })}</span>
            </div>,
            this.loading ? <LoadingIndicator display="block" /> : null,
            !this.loading && !this.cards.length ? <p className="CorkboardEmpty">{t('mine.none')}</p> : null,
            [...groups.values()].map((list) => (
              <section className="CorkboardMyCardsPage-board" style={{ '--board-color': list[0].boardColor }}>
                <h2>
                  <Link href={boardUrl({ id: list[0].boardId, slug: list[0].boardSlug })}>{list[0].board}</Link>
                </h2>
                <ul className="CorkboardCardList">{list.map((c) => cardRow(c))}</ul>
              </section>
            )),
            this.calendar(),
          ]
        )}
      </PageStructure>
    );
  }

  calendar(): Mithril.Children {
    return (
      <section className="CorkboardMyCardsPage-calendar">
        <h2>
          <Icon name="far fa-calendar" /> {t('mine.calendar')}
        </h2>
        <p className="helpText">{t('mine.calendar_help')}</p>
        {this.feed ? (
          <div className="CorkboardCardModal-inline">
            <input className="FormControl" readonly value={this.feed} aria-label={tx('mine.calendar')} onfocus={(e: Event) => (e.target as HTMLInputElement).select()} />
            <Button
              className="Button"
              onclick={() => {
                if (confirm(tx('mine.calendar_renew_confirm'))) call('POST', '/feed-token').then((a) => (this.feed = a.url));
              }}
            >
              {t('mine.calendar_renew')}
            </Button>
          </div>
        ) : (
          <Button className="Button" onclick={() => call('GET', '/feed-token').then((a) => (this.feed = a.url))}>
            {t('mine.calendar_show')}
          </Button>
        )}
      </section>
    );
  }
}

/** One card in a list (the page and the widgets): where it is, when it's due. */
export function cardRow(c: ListCard): Mithril.Children {
  const when = due(c);
  return (
    <li className={classList('CorkboardCardList-item', c.completedAt && 'is-done')}>
      <Link href={boardUrl({ id: c.boardId, slug: c.boardSlug }, c.id)}>
        <span className="CorkboardCardList-title">
          {c.priority !== 'none' ? <span className={'CorkboardPriority-dot ' + PRIORITY_CLASS[c.priority]} title={tx('priority.' + c.priority)} /> : null}
          {c.title}
        </span>
        <span className="CorkboardCardList-where">{c.column}</span>
        {when ? <span className={'CorkboardDue is-' + when.state}>{when.text}</span> : null}
      </Link>
    </li>
  );
}
