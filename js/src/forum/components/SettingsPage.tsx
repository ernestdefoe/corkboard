import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { boardUrl, call, findPeople, groupName, t, tx, write, type Group, type Person, type Visibility } from '../util';
import { PageStructure } from './core';
import { boardState, leaveBoard, type BoardState } from './BoardState';
import ColumnModal from './ColumnModal';
import { team } from './settings/team';
import { closing, general, labels } from './settings/panels';

type Tab = 'team' | 'general' | 'columns' | 'labels' | 'closing';

/** A board's settings, for its managers: team and visibility, name and links, columns, labels, closing it. */
export default class SettingsPage extends Page {
  state!: BoardState;
  tab: Tab = 'team';
  query = '';
  found: { users: Person[]; groups: Group[] } = { users: [], groups: [] };
  addRole = 'editor';
  draft: Record<string, any> = {};
  saving = false;
  private timer = 0;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.bodyClass = 'App--corkboard';
    this.state = boardState(parseInt(String(m.route.param('id')), 10));
    this.state.load().then(() => {
      const b = this.state.data?.board;
      if (b) {
        app.setTitle(tx('settings.title', { board: b.title }));
        this.reset();
      }
    });
  }

  onremove(vnode: Mithril.VnodeDOM) {
    super.onremove(vnode);
    leaveBoard(this.state.id);
  }

  reset(): void {
    const b = this.state.data!.board;
    this.draft = {
      title: b.title,
      description: b.description || '',
      color: b.color,
      tagId: b.tagId ? String(b.tagId) : '',
      projectId: b.projectId ? String(b.projectId) : '',
    };
  }

  save(body: Record<string, any>): Promise<void> {
    this.saving = true;
    return write('PATCH', 'boards', this.state.id, body)
      .then((answer) => {
        this.state.replace(answer);
        this.reset();
        app.alerts.show({ type: 'success' }, tx('settings.saved'));
      })
      .finally(() => {
        this.saving = false;
        m.redraw();
      });
  }

  view() {
    const data = this.state.data;
    if (!data) return <PageStructure className="CorkboardSettingsPage" loading={!this.state.error} />;
    const b = data.board;
    const manage = data.abilities.manage;
    const tabs: Tab[] = ['team', 'general', 'columns', 'labels', 'closing'];

    return (
      <PageStructure className="CorkboardSettingsPage">
        <div className="CorkboardSettingsPage-head">
          <div className="CorkboardBoardPage-crumbs">
            <Link href={app.route('corkboard.index')}>{t('nav')}</Link> / <Link href={boardUrl(b)}>{b.title}</Link>
          </div>
          <h1>{t('settings.heading')}</h1>
        </div>
        {!manage && !data.abilities.reopen ? <p className="CorkboardEmpty">{t('settings.not_manager')}</p> : null}
        <div className="CorkboardSettingsPage-layout">
          <nav className="CorkboardSettingsPage-tabs" role="tablist" aria-label={tx('settings.heading')}>
            {tabs.map((tab) => (
              <button
                role="tab"
                aria-selected={this.tab === tab ? 'true' : 'false'}
                className={classList('CorkboardSettingsPage-tab', this.tab === tab && 'is-active')}
                onclick={() => (this.tab = tab)}
              >
                {t('settings.tab_' + tab)}
              </button>
            ))}
          </nav>
          <div className="CorkboardSettingsPage-content">{this.panel()}</div>
        </div>
      </PageStructure>
    );
  }

  panel(): Mithril.Children {
    switch (this.tab) {
      case 'general':
        return general(this);
      case 'columns':
        return this.columns();
      case 'labels':
        return labels(this);
      case 'closing':
        return closing(this);
      default:
        return team(this);
    }
  }

  /** "Right now 19 people can see it" in words. */
  reach(): Mithril.Children {
    const data = this.state.data!;
    const b = data.board;
    if (b.visibility === 'public') return t('settings.reach_public');
    if (b.visibility === 'members') return t('settings.reach_members');
    const people = new Set<number>([b.ownerId || 0, ...data.members.filter((member) => member.userId).map((member) => member.userId!)]);
    people.delete(0);
    const groups =
      b.visibility === 'private'
        ? []
        : data.members
            .filter((member) => member.groupId)
            .map((member) => data.groups[member.groupId!])
            .filter(Boolean);
    if (groups.some((g) => g.count === null && !g.hidden)) return t('settings.reach_members');
    const inGroups = groups.reduce((n, g) => n + (g.count || 0), 0);
    return t(groups.length ? 'settings.reach_team_groups' : 'settings.reach_team', {
      people: people.size,
      groups: groups.map(groupName).join(', '),
      count: inGroups,
    });
  }

  changeVisibility(v: Visibility): void {
    const data = this.state.data!;
    if (v === 'private' && data.members.some((member) => member.groupId) && !confirm(tx('settings.private_confirm'))) return;
    this.save({ visibility: v });
  }

  search(q: string): void {
    this.query = q;
    clearTimeout(this.timer);
    if (!q.trim()) return;
    this.timer = window.setTimeout(() => {
      findPeople(q.trim(), true).then((found) => {
        this.found = found;
        m.redraw();
      });
    }, 200);
  }

  add(who: Record<string, number>): void {
    write('POST', 'members', null, { ...who, boardId: this.state.id, role: this.addRole }).then((answer) => {
      this.query = '';
      this.found = { users: [], groups: [] };
      this.state.replace(answer);
    });
  }

  role(id: number, role: string): void {
    write('PATCH', 'members', id, { role }).then((answer) => this.state.replace(answer));
  }

  remove(id: number, leaving: boolean): void {
    if (leaving && !confirm(tx('settings.leave_confirm'))) return;
    // Someone who just left may not see the board any more.
    write('DELETE', 'members', id)
      .then(() => this.state.load())
      .then(() => {
        if (this.state.error) m.route.set(app.route('corkboard.index'));
      });
  }

  columns(): Mithril.Children {
    const data = this.state.data!;
    return (
      <section className="CorkboardSettingsPage-section">
        <ul className="CorkboardSettingsList">
          {data.columns.map((c) => (
            <li>
              <span className="CorkboardSettingsList-name">
                {c.isDone ? <Icon name="fas fa-check" /> : null} {c.title}
              </span>
              <span className="CorkboardMuted">
                {[c.isDone ? tx('column.done_badge') : '', c.wipLimit ? tx('column.limit_badge', { limit: c.wipLimit }) : '']
                  .filter(Boolean)
                  .join(' · ')}
              </span>
              {data.abilities.manage ? (
                <Button className="Button" onclick={() => app.modal.show(ColumnModal, { board: this.state, column: c })}>
                  {t('card.edit')}
                </Button>
              ) : null}
            </li>
          ))}
        </ul>
        {data.abilities.manage ? (
          <Button className="Button" icon="fas fa-plus" onclick={() => app.modal.show(ColumnModal, { board: this.state })}>
            {t('board.add_column')}
          </Button>
        ) : null}
      </section>
    );
  }

  clone(): void {
    const withCards = confirm(tx('settings.clone_cards'));
    call('POST', `/boards/${this.state.id}/clone`, { cards: withCards, due: 'clear' }).then((answer) => m.route.set(boardUrl(answer)));
  }

  destroy(): void {
    const b = this.state.data!.board;
    const typed = prompt(tx('settings.delete_confirm', { title: b.title }));
    if (typed === null) return;
    if (typed.trim() !== b.title) {
      app.alerts.show({ type: 'error' }, tx('settings.delete_mismatch'));
      return;
    }
    write('DELETE', 'boards', this.state.id).then(() => m.route.set(app.route('corkboard.index')));
  }
}
