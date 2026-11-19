import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import Icon from 'flarum/common/components/Icon';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { Avatar, groupName, onColor, ROLES, t, tx, VISIBILITIES, VISIBILITY_ICON, type Group, type Person, type Visibility } from '../../util';
import type SettingsPage from '../SettingsPage';
import type { Member } from '../BoardState';

const roleOptions = () => Object.fromEntries(ROLES.map((r) => [r, tx('role.' + r)]));

/** The team tab: who can see the board, and who is on it with which role. */
export function team(page: SettingsPage): Mithril.Children {
  return [visibility(page), members(page)];
}

function visibility(page: SettingsPage): Mithril.Children {
  const data = page.state.data!;
  const b = data.board;
  const manage = data.abilities.manage;
  const allowed = (app.forum.attribute('corkboardVisibilities') as Visibility[]) || VISIBILITIES;
  const visibleTo = page.reach();
  return (
    <section className="CorkboardSettingsPage-section">
      <h2>{t('settings.visibility')}</h2>
      <div className="CorkboardVisibilityPicker CorkboardVisibilityPicker--grid">
        {VISIBILITIES.filter((v) => allowed.includes(v) || v === b.visibility).map((v) => {
          const locked = !manage || (v === 'public' && b.visibility !== 'public' && !data.abilities.public);
          return (
            <label className={classList('CorkboardVisibilityPicker-option', b.visibility === v && 'is-on', locked && 'is-locked')}>
              <input type="radio" name="ck-vis" checked={b.visibility === v} disabled={locked} onchange={() => page.changeVisibility(v)} />
              <span className="CorkboardVisibilityPicker-name">
                <Icon name={VISIBILITY_ICON[v]} /> {t('visibility.' + v)}
              </span>
              <span className="CorkboardVisibilityPicker-help">{t('visibility.' + v + '_help')}</span>
            </label>
          );
        })}
      </div>
      <p className="CorkboardSettingsPage-reach">{visibleTo}</p>
    </section>
  );
}

function members(page: SettingsPage): Mithril.Children {
  const data = page.state.data!;
  const b = data.board;
  const owner = b.ownerId ? data.users[b.ownerId] : null;
  const me = app.session.user ? Number(app.session.user.id()) : 0;
  return (
    <section className="CorkboardSettingsPage-section">
      <h2>{t('settings.team')}</h2>
      {data.abilities.manage ? addRow(page) : null}
      {page.query && (page.found.users.length || page.found.groups.length) ? found(page) : null}
      <ul className="CorkboardTeamList">
        <li className="CorkboardTeamList-row">
          <Avatar user={owner} size={32} />
          <span className="CorkboardTeamList-name">
            <strong>{owner ? owner.name : tx('settings.no_owner')}</strong>
            <span className="CorkboardMuted">{t('settings.owner')}</span>
          </span>
          <span className="CorkboardTeamList-role">{t('role.manager')}</span>
        </li>
        {data.members.map((member) => memberRow(page, member, me))}
      </ul>
      <p className="helpText">{t('settings.roles_help')}</p>
    </section>
  );
}

function addRow(page: SettingsPage): Mithril.Children {
  const b = page.state.data!.board;
  return (
    <div className="CorkboardSettingsPage-add">
      <input
        className="FormControl"
        type="search"
        placeholder={tx(b.visibility === 'private' ? 'settings.add_person' : 'settings.add_member')}
        aria-label={tx(b.visibility === 'private' ? 'settings.add_person' : 'settings.add_member')}
        value={page.query}
        oninput={(e: Event) => page.search((e.target as HTMLInputElement).value)}
      />
      <Select aria-label={tx('settings.role')} value={page.addRole} options={roleOptions()} onchange={(value: string) => (page.addRole = value)} />
    </div>
  );
}

function found(page: SettingsPage): Mithril.Children {
  const b = page.state.data!.board;
  return (
    <ul className="CorkboardPicker-list CorkboardSettingsPage-found">
      {page.found.users.map((u) => (
        <li>
          <button onclick={() => page.add({ userId: u.id })}>
            <Avatar user={u} size={24} /> {u.name} <span className="CorkboardMuted">@{u.username}</span>
          </button>
        </li>
      ))}
      {b.visibility !== 'private'
        ? page.found.groups.map((g) => (
            <li>
              <button onclick={() => page.add({ groupId: g.id })}>
                <span className="CorkboardGroupIcon" style={{ background: g.color || '#5a6575', color: onColor(g.color) }}>
                  <Icon name={g.icon || 'fas fa-users'} />
                </span>{' '}
                {g.name} <span className="CorkboardMuted">{t('settings.group')}</span>
              </button>
            </li>
          ))
        : null}
    </ul>
  );
}

function memberRow(page: SettingsPage, member: Member, me: number): Mithril.Children {
  const data = page.state.data!;
  const manage = data.abilities.manage;
  const user = member.userId ? data.users[member.userId] : null;
  const group = member.groupId ? data.groups[member.groupId] : null;
  return (
    <li className="CorkboardTeamList-row" key={member.id}>
      {memberPicture(user, group)}
      <span className="CorkboardTeamList-name">
        <strong>{user ? user.name : groupName(group)}</strong>
        <span className="CorkboardMuted">
          {group
            ? group.hidden && group.name === null
              ? null
              : group.count === null
                ? t('settings.everyone_signed_in')
                : t('settings.group_count', { count: group.count })
            : '@' + user?.username}
        </span>
      </span>
      {manage ? (
        <Select
          className="CorkboardTeamList-role"
          aria-label={tx('settings.role_for', { name: user ? user.name : groupName(group) })}
          value={member.role}
          options={roleOptions()}
          onchange={(value: string) => page.role(member.id, value)}
        />
      ) : (
        <span className="CorkboardTeamList-role">{t('role.' + member.role)}</span>
      )}
      {manage || member.userId === me ? (
        <Button
          className="Button Button--icon Button--link"
          icon="fas fa-xmark"
          aria-label={tx(member.userId === me ? 'settings.leave' : 'settings.remove', { name: user ? user.name : groupName(group) })}
          onclick={() => page.remove(member.id, member.userId === me)}
        />
      ) : null}
    </li>
  );
}

function memberPicture(user: Person | null, group: Group | null): Mithril.Children {
  return user ? (
    <Avatar user={user} size={32} />
  ) : (
    <span className="CorkboardGroupIcon CorkboardGroupIcon--big" style={{ background: group?.color || '#5a6575', color: onColor(group?.color) }}>
      <Icon name={group?.icon || 'fas fa-users'} />
    </span>
  );
}
