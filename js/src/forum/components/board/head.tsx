import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { Avatar, groupName, t, tx, VISIBILITY_ICON } from '../../util';
import type BoardPage from '../BoardPage';
import type { Filters } from '../BoardPage';
import type { BoardPayload } from '../BoardState';
import ActivityModal from '../ActivityModal';

/** The board's head: crumbs, title row, description, the closed banner and the filters. */
export function head(page: BoardPage): Mithril.Children {
  const b = page.state.data!.board;
  return (
    <div className="CorkboardBoardPage-head" style={{ '--board-color': b.color }}>
      <div className="CorkboardBoardPage-crumbs">
        <Link href={app.route('corkboard.index')}>{t('nav')}</Link>
        {b.project ? [' / ', <Link href={app.route('projects.show', { slug: b.project.slug })}>{b.project.title}</Link>] : null}
      </div>
      {titleRow(page)}
      {b.description ? <p className="CorkboardBoardPage-description">{b.description}</p> : null}
      {b.status !== 'active' ? closedBanner(page) : null}
      {filterBar(page)}
    </div>
  );
}

function titleRow(page: BoardPage): Mithril.Children {
  const s = page.state;
  const data = s.data!;
  const b = data.board;
  return (
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
      {team(data)}
      {app.session.user ? (
        <Button
          className="Button Button--icon"
          icon={data.pinned ? 'fas fa-thumbtack' : 'fas fa-thumbtack'}
          aria-label={tx(data.pinned ? 'board.unpin' : 'board.pin')}
          aria-pressed={data.pinned ? 'true' : 'false'}
          onclick={() => page.pin()}
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
  );
}

function team(data: BoardPayload): Mithril.Children {
  const ids = [data.board.ownerId, ...data.members.filter((member) => member.userId).map((member) => member.userId)].filter(
    (id, i, a) => id && a.indexOf(id) === i
  ) as number[];
  const groups = data.members
    .filter((member) => member.groupId)
    .map((member) => data.groups[member.groupId!])
    .filter(Boolean);
  if (!ids.length && !groups.length) return null;
  return (
    <div className="CorkboardTeam" aria-label={tx('board.team')}>
      {ids.slice(0, 6).map((id) => (
        <Avatar user={data.users[id]} size={30} ring />
      ))}
      {ids.length > 6 ? <span className="CorkboardTeam-more">+{ids.length - 6}</span> : null}
      {groups.length ? <span className="CorkboardTeam-groups">+ {groups.map(groupName).join(', ')}</span> : null}
    </div>
  );
}

function closedBanner(page: BoardPage): Mithril.Children {
  const data = page.state.data!;
  const b = data.board;
  const when = b.status === 'completed' ? b.completedAt : b.archivedAt;
  return (
    <div className={'CorkboardClosed CorkboardClosed--' + b.status}>
      <Icon name={b.status === 'completed' ? 'fas fa-flag-checkered' : 'fas fa-box-archive'} />
      <span>{t('board.closed_' + b.status, { date: when ? new Date(when).toLocaleDateString() : '' })}</span>
      {data.abilities.reopen ? (
        <Button className="Button Button--primary" onclick={() => page.reopen()}>
          {t('board.reopen')}
        </Button>
      ) : null}
    </div>
  );
}

function filterBar(page: BoardPage): Mithril.Children {
  const data = page.state.data!;
  const f = page.filters;
  const done = data.board.doneCount;
  const late = data.cards.filter((c) => !c.completedAt && c.dueAt && new Date(c.dueAt).getTime() < Date.now()).length;
  return (
    <div className={classList('CorkboardFilters', page.filtersOpen && 'is-open')}>
      <button
        className={classList('CorkboardToggle CorkboardFilters-toggle', page.filtering() && 'is-on')}
        aria-expanded={page.filtersOpen ? 'true' : 'false'}
        onclick={() => (page.filtersOpen = !page.filtersOpen)}
      >
        <Icon name="fas fa-filter" /> {t('board.filters')}
      </button>
      <input
        className="FormControl CorkboardFilters-search"
        type="search"
        placeholder={tx('board.filter_placeholder')}
        aria-label={tx('board.filter_placeholder')}
        value={f.q}
        oninput={(e: Event) => page.setFilter('q', (e.target as HTMLInputElement).value)}
      />
      {app.session.user ? toggle(page, 'mine', 'board.filter_mine') : null}
      {data.labels.length
        ? select(
            page,
            'label',
            [['', tx('board.filter_labels')], ...data.labels.map((l) => [String(l.id), l.name || tx('label.unnamed')] as [string, string])],
            tx('board.filter_labels')
          )
        : null}
      {select(page, 'due', dueOptions(), tx('board.filter_due'))}
      {select(
        page,
        'priority',
        [['', tx('board.filter_priority')], ...['urgent', 'high', 'normal', 'low'].map((p) => [p, tx('priority.' + p)] as [string, string])],
        tx('board.filter_priority')
      )}
      {toggle(page, 'hideDone', 'board.filter_hide_done')}
      <span className="CorkboardFilters-summary">{t('board.summary', { done, total: data.board.cardCount, late })}</span>
    </div>
  );
}

function toggle(page: BoardPage, key: 'mine' | 'hideDone', label: string): Mithril.Children {
  const on = page.filters[key];
  return (
    <button
      className={classList('CorkboardToggle', on && 'is-on')}
      aria-pressed={on ? 'true' : 'false'}
      onclick={() => page.setFilter(key, !page.filters[key])}
    >
      {t(label)}
    </button>
  );
}

function dueOptions(): Array<[string, any]> {
  return [
    ['', tx('board.filter_due')],
    ['late', tx('board.due_late')],
    ['week', tx('board.due_week')],
    ['none', tx('board.due_none')],
  ];
}

/** Core's Select markup, so it gets the theme's caret. */
function select(page: BoardPage, key: keyof Filters, options: Array<[string, any]>, label: string): Mithril.Children {
  const f = page.filters;
  return (
    <span className={classList('Select CorkboardFilters-select', f[key] ? 'is-on' : '')}>
      <select
        className="Select-input FormControl"
        aria-label={label}
        value={String(f[key] ?? '')}
        onchange={(e: Event) => page.setFilter(key, (e.target as HTMLSelectElement).value)}
      >
        {options.map(([value, text]) => (
          <option value={value}>{text}</option>
        ))}
      </select>
      <Icon name="fas fa-sort" className="Select-caret" />
    </span>
  );
}
