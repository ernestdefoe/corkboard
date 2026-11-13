import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { ago, Avatar, boardUrl, call, t, tx, VISIBILITY_ICON, type Person, type Visibility } from '../util';
import { IndexSidebar, PageStructure } from './core';
import CreateBoardModal, { type Template } from './CreateBoardModal';

type Tab = 'mine' | 'all' | 'completed' | 'archived' | 'templates';

interface Tile {
  id: number;
  slug: string;
  title: string;
  description: string | null;
  color: string;
  visibility: Visibility;
  status: string;
  cardCount: number;
  doneCount: number;
  lastActivityAt: string | null;
  people: number[];
  teamSize: number;
  dueSoon: number;
  pinned: boolean;
}

/** /corkboard: the viewer's boards (pinned first), every board they can see, the closed ones, and templates. */
export default class BoardsPage extends Page {
  tab: Tab = 'mine';
  boards: Tile[] = [];
  pinned: Tile[] = [];
  users: Record<string, Person> = {};
  more = false;
  page = 0;
  loading = true;
  query = '';
  templates: { builtIn: Template[]; custom: Template[] } | null = null;
  private timer = 0;
  private ticket = 0;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.bodyClass = 'App--corkboard';
    app.setTitle(tx('nav'));
    app.history.push('corkboard.index', tx('nav'));
    const tab = new URLSearchParams(window.location.search).get('tab') as Tab | null;
    this.tab = tab && ['mine', 'all', 'completed', 'archived', 'templates'].includes(tab) ? tab : app.session.user ? 'mine' : 'all';
    this.load(0);
    if (app.session.user) {
      call('GET', '/templates', undefined, undefined, true).then((tpl) => {
        this.templates = tpl;
        m.redraw();
      });
    }
  }

  load(page: number): void {
    const ticket = ++this.ticket;
    this.loading = true;
    call('GET', '/boards', undefined, { tab: this.tab, q: this.query || undefined, page }, true)
      .then((answer) => {
        if (ticket !== this.ticket) return;
        this.boards = page ? [...this.boards, ...answer.boards] : answer.boards;
        if (!page) this.pinned = answer.pinned;
        Object.assign(this.users, answer.users);
        this.more = answer.more;
        this.page = page;
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  setTab(tab: Tab): void {
    this.tab = tab;
    window.history.replaceState(window.history.state, '', app.route('corkboard.index') + (tab === 'mine' ? '' : `?tab=${tab}`));
    this.load(0);
  }

  view() {
    const canCreate = app.forum.attribute('corkboardCanCreate');
    const pinnedIds = new Set(this.pinned.map((b) => b.id));
    const rest = this.tab === 'mine' || this.tab === 'all' ? this.boards.filter((b) => !pinnedIds.has(b.id) || this.query) : this.boards;
    const tabs: Tab[] = app.session.user ? ['mine', 'all', 'completed', 'archived'] : ['all', 'completed'];
    if (app.forum.attribute('corkboardCanCreate') && this.templates?.custom.length) tabs.push('templates');

    return (
      <PageStructure className="CorkboardBoardsPage" sidebar={() => <IndexSidebar />}>
        <div className="CorkboardBoardsPage-head">
          <h1 className="CorkboardBoardsPage-title">{t('nav')}</h1>
          {canCreate ? (
            <Button className="Button Button--primary" icon="fas fa-plus" onclick={() => app.modal.show(CreateBoardModal, { templates: this.templates || undefined })}>
              {t('list.start')}
            </Button>
          ) : null}
        </div>
        <div className="CorkboardBoardsPage-toolbar">
          <div className="CorkboardSegments" role="tablist">
            {tabs.map((tab) => (
              <button role="tab" aria-selected={this.tab === tab ? 'true' : 'false'} className={classList('CorkboardSegments-tab', this.tab === tab && 'is-active')} onclick={() => this.setTab(tab)}>
                {t('list.tab_' + tab)}
              </button>
            ))}
          </div>
          <input
            className="FormControl CorkboardBoardsPage-search"
            type="search"
            placeholder={tx('list.search')}
            aria-label={tx('list.search')}
            value={this.query}
            oninput={(e: Event) => {
              this.query = (e.target as HTMLInputElement).value;
              clearTimeout(this.timer);
              this.timer = window.setTimeout(() => this.load(0), 250);
            }}
          />
        </div>
        {this.pinned.length && !this.query && (this.tab === 'mine' || this.tab === 'all') ? this.section(tx('list.pinned'), this.pinned) : null}
        {rest.length ? this.section(this.pinned.length && !this.query ? tx('list.tab_' + this.tab) : null, rest) : null}
        {this.loading ? <LoadingIndicator display="block" /> : null}
        {!this.loading && !rest.length && !this.pinned.length ? <p className="CorkboardEmpty">{t(this.query ? 'list.none_found' : 'list.none_' + this.tab)}</p> : null}
        {this.more && !this.loading ? (
          <Button className="Button Button--block" onclick={() => this.load(this.page + 1)}>
            {t('list.more')}
          </Button>
        ) : null}
        {canCreate && this.templates && this.tab === 'mine' ? this.templateRow() : null}
      </PageStructure>
    );
  }

  section(title: string | null, boards: Tile[]): Mithril.Children {
    return (
      <section className="CorkboardBoardsPage-section">
        {title ? <h2 className="CorkboardBoardsPage-label">{title}</h2> : null}
        <div className="CorkboardTiles">{boards.map((b) => this.tile(b))}</div>
      </section>
    );
  }

  tile(b: Tile): Mithril.Children {
    const pct = b.cardCount ? Math.round((b.doneCount / b.cardCount) * 100) : 0;
    return (
      <Link className="CorkboardTile" href={boardUrl(b)} style={{ '--board-color': b.color }}>
        <span className="CorkboardTile-stripe" />
        <span className="CorkboardTile-body">
          <span className="CorkboardTile-titleRow">
            <span className="CorkboardTile-title">{b.title}</span>
            {b.pinned ? <Icon name="fas fa-thumbtack" className="CorkboardTile-pin" /> : null}
            <span className={'CorkboardChip CorkboardChip--' + b.visibility}>
              <Icon name={VISIBILITY_ICON[b.visibility]} />
              {t('visibility.' + b.visibility)}
            </span>
          </span>
          {b.description ? <span className="CorkboardTile-description">{b.description}</span> : null}
          <span className="CorkboardProgress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow={pct} aria-label={tx('list.progress', { done: b.doneCount, total: b.cardCount })}>
            <span className="CorkboardProgress-bar" style={{ width: `${pct}%` }} />
          </span>
          <span className="CorkboardTile-meta">
            <span className="CorkboardTile-people">
              {b.people.map((id) => (
                <Avatar user={this.users[id]} size={22} ring />
              ))}
            </span>
            <span>{t('list.progress', { done: b.doneCount, total: b.cardCount })}</span>
            {b.dueSoon ? <span className="CorkboardDue is-soon">{t('list.due_soon', { count: b.dueSoon })}</span> : null}
            <span className="CorkboardTile-spacer" />
            <span>{ago(b.lastActivityAt)}</span>
          </span>
        </span>
      </Link>
    );
  }

  templateRow(): Mithril.Children {
    return (
      <section className="CorkboardBoardsPage-section">
        <h2 className="CorkboardBoardsPage-label">{t('list.from_template')}</h2>
        <div className="CorkboardTemplates">
          {[...this.templates!.builtIn, ...this.templates!.custom].map((tpl) => (
            <button className="CorkboardTemplate" onclick={() => app.modal.show(CreateBoardModal, { template: tpl.key, templates: this.templates! })}>
              <span className="CorkboardTemplate-name">{tpl.title}</span>
              <span className="CorkboardTemplate-columns">
                {tpl.columns.map((c) => (
                  <span>{c}</span>
                ))}
              </span>
            </button>
          ))}
        </div>
      </section>
    );
  }
}
