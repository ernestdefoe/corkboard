import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { Avatar, boardUrl, call, onColor, ROLES, t, tx, VISIBILITIES, VISIBILITY_ICON, type Group, type Person, type Visibility } from '../util';
import { PageStructure } from './core';
import { boardState, leaveBoard, type BoardState } from './BoardState';
import ColumnModal from './ColumnModal';

type Tab = 'team' | 'general' | 'columns' | 'labels' | 'closing';

const COLORS = ['#4d698e', '#1971c2', '#0b7285', '#2b8a3e', '#c2410c', '#c92a2a', '#a61e4d', '#6741d9', '#5a6575'];
const roleOptions = () => Object.fromEntries(ROLES.map((r) => [r, tx('role.' + r)]));
const LABEL_COLORS = ['#2b8a3e', '#f2c94c', '#c2410c', '#c92a2a', '#6741d9', '#1971c2', '#0b7285', '#a61e4d', '#5a6575'];

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
    return call('PATCH', `/boards/${this.state.id}`, body)
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
        return this.general();
      case 'columns':
        return this.columns();
      case 'labels':
        return this.labels();
      case 'closing':
        return this.closing();
      default:
        return this.team();
    }
  }

  team(): Mithril.Children {
    const data = this.state.data!;
    const b = data.board;
    const manage = data.abilities.manage;
    const allowed = (app.forum.attribute('corkboardVisibilities') as Visibility[]) || VISIBILITIES;
    const owner = b.ownerId ? data.users[b.ownerId] : null;
    const me = app.session.user ? Number(app.session.user.id()) : 0;
    const visibleTo = this.reach();

    return [
      <section className="CorkboardSettingsPage-section">
        <h2>{t('settings.visibility')}</h2>
        <div className="CorkboardVisibilityPicker CorkboardVisibilityPicker--grid">
          {VISIBILITIES.filter((v) => allowed.includes(v) || v === b.visibility).map((v) => {
            const locked = !manage || (v === 'public' && b.visibility !== 'public' && !data.abilities.public);
            return (
              <label className={classList('CorkboardVisibilityPicker-option', b.visibility === v && 'is-on', locked && 'is-locked')}>
                <input type="radio" name="ck-vis" checked={b.visibility === v} disabled={locked} onchange={() => this.changeVisibility(v)} />
                <span className="CorkboardVisibilityPicker-name">
                  <Icon name={VISIBILITY_ICON[v]} /> {t('visibility.' + v)}
                </span>
                <span className="CorkboardVisibilityPicker-help">{t('visibility.' + v + '_help')}</span>
              </label>
            );
          })}
        </div>
        <p className="CorkboardSettingsPage-reach">{visibleTo}</p>
      </section>,
      <section className="CorkboardSettingsPage-section">
        <h2>{t('settings.team')}</h2>
        {manage ? (
          <div className="CorkboardSettingsPage-add">
            <input
              className="FormControl"
              type="search"
              placeholder={tx(b.visibility === 'private' ? 'settings.add_person' : 'settings.add_member')}
              aria-label={tx(b.visibility === 'private' ? 'settings.add_person' : 'settings.add_member')}
              value={this.query}
              oninput={(e: Event) => this.search((e.target as HTMLInputElement).value)}
            />
            <Select
              aria-label={tx('settings.role')}
              value={this.addRole}
              options={roleOptions()}
              onchange={(value: string) => (this.addRole = value)}
            />
          </div>
        ) : null}
        {this.query && (this.found.users.length || this.found.groups.length) ? (
          <ul className="CorkboardPicker-list CorkboardSettingsPage-found">
            {this.found.users.map((u) => (
              <li>
                <button onclick={() => this.add({ userId: u.id })}>
                  <Avatar user={u} size={24} /> {u.name} <span className="CorkboardMuted">@{u.username}</span>
                </button>
              </li>
            ))}
            {b.visibility !== 'private'
              ? this.found.groups.map((g) => (
                  <li>
                    <button onclick={() => this.add({ groupId: g.id })}>
                      <span className="CorkboardGroupIcon" style={{ background: g.color || '#5a6575', color: onColor(g.color) }}>
                        <Icon name={g.icon || 'fas fa-users'} />
                      </span>{' '}
                      {g.name} <span className="CorkboardMuted">{t('settings.group')}</span>
                    </button>
                  </li>
                ))
              : null}
          </ul>
        ) : null}
        <ul className="CorkboardTeamList">
          <li className="CorkboardTeamList-row">
            <Avatar user={owner} size={32} />
            <span className="CorkboardTeamList-name">
              <strong>{owner ? owner.name : tx('settings.no_owner')}</strong>
              <span className="CorkboardMuted">{t('settings.owner')}</span>
            </span>
            <span className="CorkboardTeamList-role">{t('role.manager')}</span>
          </li>
          {data.members.map((member) => {
            const user = member.userId ? data.users[member.userId] : null;
            const group = member.groupId ? data.groups[member.groupId] : null;
            return (
              <li className="CorkboardTeamList-row" key={member.id}>
                {user ? (
                  <Avatar user={user} size={32} />
                ) : (
                  <span
                    className="CorkboardGroupIcon CorkboardGroupIcon--big"
                    style={{ background: group?.color || '#5a6575', color: onColor(group?.color) }}
                  >
                    <Icon name={group?.icon || 'fas fa-users'} />
                  </span>
                )}
                <span className="CorkboardTeamList-name">
                  <strong>{user ? user.name : group?.name}</strong>
                  <span className="CorkboardMuted">
                    {group
                      ? group.count === null
                        ? t('settings.everyone_signed_in')
                        : t('settings.group_count', { count: group.count })
                      : '@' + user?.username}
                  </span>
                </span>
                {manage ? (
                  <Select
                    className="CorkboardTeamList-role"
                    aria-label={tx('settings.role_for', { name: user ? user.name : group?.name || '' })}
                    value={member.role}
                    options={roleOptions()}
                    onchange={(value: string) => this.role(member.id, value)}
                  />
                ) : (
                  <span className="CorkboardTeamList-role">{t('role.' + member.role)}</span>
                )}
                {manage || member.userId === me ? (
                  <Button
                    className="Button Button--icon Button--link"
                    icon="fas fa-xmark"
                    aria-label={tx(member.userId === me ? 'settings.leave' : 'settings.remove', { name: user ? user.name : group?.name || '' })}
                    onclick={() => this.remove(member.id, member.userId === me)}
                  />
                ) : null}
              </li>
            );
          })}
        </ul>
        <p className="helpText">{t('settings.roles_help')}</p>
      </section>,
    ];
  }

  /** "Right now 19 people can see it" in words. */
  reach(): Mithril.Children {
    const data = this.state.data!;
    const b = data.board;
    if (b.visibility === 'public') return t('settings.reach_public');
    if (b.visibility === 'members') return t('settings.reach_members');
    const people = new Set<number>([b.ownerId || 0, ...data.members.filter((m) => m.userId).map((m) => m.userId!)]);
    people.delete(0);
    const groups =
      b.visibility === 'private'
        ? []
        : data.members
            .filter((m) => m.groupId)
            .map((m) => data.groups[m.groupId!])
            .filter(Boolean);
    if (groups.some((g) => g.count === null)) return t('settings.reach_members');
    const inGroups = groups.reduce((n, g) => n + (g.count || 0), 0);
    return t(groups.length ? 'settings.reach_team_groups' : 'settings.reach_team', {
      people: people.size,
      groups: groups.map((g) => g.name).join(', '),
      count: inGroups,
    });
  }

  changeVisibility(v: Visibility): void {
    const data = this.state.data!;
    if (v === 'private' && data.members.some((m) => m.groupId) && !confirm(tx('settings.private_confirm'))) return;
    this.save({ visibility: v });
  }

  search(q: string): void {
    this.query = q;
    clearTimeout(this.timer);
    if (!q.trim()) return;
    this.timer = window.setTimeout(() => {
      call('GET', '/people', undefined, { q: q.trim(), groups: 1 }, true).then((found) => {
        this.found = found;
        m.redraw();
      });
    }, 200);
  }

  add(who: Record<string, number>): void {
    call('POST', `/boards/${this.state.id}/members`, { ...who, role: this.addRole }).then((answer) => {
      this.query = '';
      this.found = { users: [], groups: [] };
      this.state.replace(answer);
    });
  }

  role(id: number, role: string): void {
    call('PATCH', `/members/${id}`, { role }).then((answer) => this.state.replace(answer));
  }

  remove(id: number, leaving: boolean): void {
    if (leaving && !confirm(tx('settings.leave_confirm'))) return;
    call('DELETE', `/members/${id}`).then((answer) => {
      if (answer) this.state.replace(answer);
      else m.route.set(app.route('corkboard.index'));
    });
  }

  general(): Mithril.Children {
    const data = this.state.data!;
    const manage = data.abilities.manage;
    const d = this.draft;
    const tags = 'flarum-tags' in ((window as any).flarum?.extensions || {}) ? (app.store.all('tags') as any[]) : [];
    return (
      <section className="CorkboardSettingsPage-section">
        <div className="Form">
          <div className="Form-group">
            <label for="ck-title">{t('settings.name')}</label>
            <input
              id="ck-title"
              className="FormControl"
              maxlength={160}
              disabled={!manage}
              value={d.title}
              oninput={(e: Event) => (d.title = (e.target as HTMLInputElement).value)}
            />
          </div>
          <div className="Form-group">
            <label for="ck-description">{t('settings.description')}</label>
            <textarea
              id="ck-description"
              className="FormControl"
              rows={3}
              maxlength={1000}
              disabled={!manage}
              value={d.description}
              oninput={(e: Event) => (d.description = (e.target as HTMLTextAreaElement).value)}
            />
          </div>
          <fieldset className="Form-group">
            <legend>{t('settings.color')}</legend>
            <div className="CorkboardSwatches">
              {COLORS.map((c) => (
                <button
                  type="button"
                  className={classList('CorkboardSwatch', d.color === c && 'is-on')}
                  style={{ background: c }}
                  aria-label={c}
                  aria-pressed={d.color === c ? 'true' : 'false'}
                  disabled={!manage}
                  onclick={() => (d.color = c)}
                />
              ))}
            </div>
          </fieldset>
          {tags.length ? (
            <div className="Form-group">
              <label for="ck-tag">{t('settings.tag')}</label>
              <Select
                id="ck-tag"
                disabled={!manage}
                value={d.tagId}
                options={{ '': tx('settings.no_tag'), ...Object.fromEntries(tags.map((tag) => [tag.id(), tag.name()])) }}
                onchange={(value: string) => (d.tagId = value)}
              />
              <p className="helpText">{t('settings.tag_help')}</p>
            </div>
          ) : null}
          {data.projects && (data.projects.length || data.board.project) ? (
            <div className="Form-group">
              <label for="ck-project">{t('settings.project')}</label>
              <Select
                id="ck-project"
                disabled={!manage}
                value={d.projectId}
                options={{
                  '': tx('settings.no_project'),
                  ...(data.board.project ? { [String(data.board.project.id)]: data.board.project.title } : {}),
                  ...Object.fromEntries(data.projects.map((p) => [String(p.id), p.title])),
                }}
                onchange={(value: string) => (d.projectId = value)}
              />
            </div>
          ) : null}
          {manage ? (
            <div className="Form-group">
              <Button
                className="Button Button--primary"
                loading={this.saving}
                onclick={() =>
                  this.save({
                    title: d.title,
                    description: d.description,
                    color: d.color,
                    ...(tags.length ? { tagId: d.tagId || null } : {}),
                    ...(data.projects ? { projectId: d.projectId || null } : {}),
                  })
                }
              >
                {t('save')}
              </Button>
            </div>
          ) : null}
        </div>
      </section>
    );
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

  labels(): Mithril.Children {
    const data = this.state.data!;
    const manage = data.abilities.manage;
    return (
      <section className="CorkboardSettingsPage-section">
        <p className="helpText">{t('settings.labels_help')}</p>
        <ul className="CorkboardSettingsList">
          {data.labels.map((l) => (
            <li key={l.id}>
              <span className="CorkboardLabel CorkboardLabel--wide" style={{ background: l.color, color: onColor(l.color) }}>
                {l.name || tx('label.unnamed')}
              </span>
              {manage ? (
                <input
                  className="FormControl"
                  maxlength={40}
                  aria-label={tx('settings.label_name')}
                  placeholder={tx('settings.label_name')}
                  value={l.name}
                  onchange={(e: Event) =>
                    call('PATCH', `/labels/${l.id}`, { name: (e.target as HTMLInputElement).value }).then((a) => this.state.replace(a))
                  }
                />
              ) : null}
              {manage ? (
                <Select
                  className="CorkboardSettingsList-color"
                  aria-label={tx('settings.label_color')}
                  value={l.color}
                  options={Object.fromEntries(LABEL_COLORS.map((c) => [c, tx('color.' + c.slice(1))]))}
                  onchange={(value: string) => call('PATCH', `/labels/${l.id}`, { color: value }).then((a) => this.state.replace(a))}
                />
              ) : null}
              {manage ? (
                <Button
                  className="Button Button--icon Button--link"
                  icon="far fa-trash-can"
                  aria-label={tx('settings.label_delete')}
                  onclick={() => call('DELETE', `/labels/${l.id}`).then((a) => this.state.replace(a))}
                />
              ) : null}
            </li>
          ))}
        </ul>
        {manage ? (
          <Button
            className="Button"
            icon="fas fa-plus"
            onclick={() =>
              call('POST', `/boards/${this.state.id}/labels`, { name: '', color: LABEL_COLORS[data.labels.length % LABEL_COLORS.length] }).then((a) =>
                this.state.replace(a)
              )
            }
          >
            {t('settings.add_label')}
          </Button>
        ) : null}
      </section>
    );
  }

  closing(): Mithril.Children {
    const data = this.state.data!;
    const b = data.board;
    const me = app.session.user ? Number(app.session.user.id()) : 0;
    const owner = b.ownerId === me || app.forum.attribute('corkboardCanModerate');
    return (
      <section className="CorkboardSettingsPage-section CorkboardSettingsPage-closing">
        {b.status === 'active' && data.abilities.manage ? (
          <div className="CorkboardSettingsPage-action">
            <div>
              <strong>{t('settings.complete')}</strong>
              <p className="helpText">{t('settings.complete_help')}</p>
            </div>
            <Button className="Button" onclick={() => this.save({ status: 'completed' })}>
              {t('settings.complete')}
            </Button>
          </div>
        ) : null}
        {b.status !== 'active' && data.abilities.reopen ? (
          <div className="CorkboardSettingsPage-action">
            <div>
              <strong>{t('board.reopen')}</strong>
              <p className="helpText">{t('settings.reopen_help')}</p>
            </div>
            <Button className="Button Button--primary" onclick={() => this.save({ status: 'active' })}>
              {t('board.reopen')}
            </Button>
          </div>
        ) : null}
        {app.forum.attribute('corkboardCanCreate') ? (
          <div className="CorkboardSettingsPage-action">
            <div>
              <strong>{t('settings.clone')}</strong>
              <p className="helpText">{t('settings.clone_help')}</p>
            </div>
            <Button className="Button" onclick={() => this.clone()}>
              {t('settings.clone')}
            </Button>
          </div>
        ) : null}
        {data.abilities.template ? (
          <div className="CorkboardSettingsPage-action">
            <div>
              <strong>{t(b.isTemplate ? 'settings.untemplate' : 'settings.template')}</strong>
              <p className="helpText">{t('settings.template_help')}</p>
            </div>
            <Button className="Button" onclick={() => this.save({ isTemplate: !b.isTemplate })}>
              {t(b.isTemplate ? 'settings.untemplate' : 'settings.template')}
            </Button>
          </div>
        ) : null}
        {b.status === 'active' && data.abilities.manage ? (
          <div className="CorkboardSettingsPage-action">
            <div>
              <strong>{t('settings.archive')}</strong>
              <p className="helpText">{t('settings.archive_help')}</p>
            </div>
            <Button className="Button" onclick={() => this.save({ status: 'archived' })}>
              {t('settings.archive')}
            </Button>
          </div>
        ) : null}
        {owner ? (
          <div className="CorkboardSettingsPage-action CorkboardSettingsPage-action--danger">
            <div>
              <strong>{t('settings.delete')}</strong>
              <p className="helpText">{t('settings.delete_help')}</p>
            </div>
            <Button className="Button Button--danger" onclick={() => this.destroy()}>
              {t('settings.delete')}
            </Button>
          </div>
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
    call('DELETE', `/boards/${this.state.id}`).then(() => m.route.set(app.route('corkboard.index')));
  }
}
