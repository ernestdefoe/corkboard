import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import Icon from 'flarum/common/components/Icon';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { Avatar, call, due, onColor, PRIORITIES, t, tx, type Person } from '../../util';
import type CardModal from '../CardModal';
import MoveCardModal from '../MoveCardModal';
import type { Detail } from './types';

const COVERS = ['#1971c2', '#2b8a3e', '#f2c94c', '#c2410c', '#c92a2a', '#6741d9', '#0b7285'];

/** The right-hand column: people, priority, due date, labels, column, cover and the actions. */
export function side(modal: CardModal, card: Detail): Mithril.Children {
  const data = modal.attrs.board.data!;
  const edit = card.abilities.edit;
  return [
    assignees(modal, card, { ...data.users, ...card.users }),
    <div className="CorkboardField">
      <h4>{t('card.priority')}</h4>
      {edit ? (
        <Select
          aria-label={tx('card.priority')}
          value={card.priority}
          options={Object.fromEntries(PRIORITIES.map((p) => [p, tx('priority.' + p)]))}
          onchange={(value: string) => modal.save({ priority: value })}
        />
      ) : (
        <p>{t('priority.' + card.priority)}</p>
      )}
    </div>,
    dueField(modal, card),
    data.labels.length ? labels(modal, card) : null,
    edit ? (
      <div className="CorkboardField">
        <h4>{t('card.column')}</h4>
        <Select
          aria-label={tx('card.column')}
          value={String(card.columnId)}
          options={Object.fromEntries(data.columns.map((c) => [String(c.id), c.title]))}
          onchange={(value: string) => modal.save({ columnId: Number(value) })}
        />
      </div>
    ) : null,
    edit ? cover(modal, card) : null,
    actions(modal, card),
  ];
}

function assignees(modal: CardModal, card: Detail, users: Record<string, Person>): Mithril.Children {
  const edit = card.abilities.edit;
  return (
    <div className="CorkboardField">
      <h4>{t('card.assignees')}</h4>
      <ul className="CorkboardPeople">
        {card.assigneeIds.map((id) => (
          <li>
            <Avatar user={users[id]} size={26} />
            <span>{users[id]?.name}</span>
            {edit ? (
              <Button
                className="Button Button--icon Button--link"
                icon="fas fa-xmark"
                aria-label={tx('card.unassign', { name: users[id]?.name || '' })}
                onclick={() => modal.save({ assigneeIds: card.assigneeIds.filter((x) => x !== id) })}
              />
            ) : null}
          </li>
        ))}
      </ul>
      {!card.assigneeIds.length ? <p className="CorkboardMuted">{t('card.nobody')}</p> : null}
      {edit ? assignPicker(modal, card) : null}
    </div>
  );
}

function assignPicker(modal: CardModal, card: Detail): Mithril.Children {
  if (!modal.assigning) {
    return (
      <Button className="Button Button--link" icon="fas fa-user-plus" onclick={() => (modal.assigning = true)}>
        {t('card.assign')}
      </Button>
    );
  }
  const data = modal.attrs.board.data!;
  const team = [data.board.ownerId, ...data.members.filter((member) => member.userId).map((member) => member.userId)]
    .filter((id, i, a) => id && a.indexOf(id) === i && !card.assigneeIds.includes(id as number))
    .map((id) => data.users[id as number])
    .filter(Boolean) as Person[];
  const list = modal.peopleQuery ? modal.people.filter((p) => !card.assigneeIds.includes(p.id)) : team;
  return (
    <div className="CorkboardPicker">
      <input
        className="FormControl"
        type="search"
        placeholder={tx('card.find_person')}
        aria-label={tx('card.find_person')}
        oncreate={(v: Mithril.VnodeDOM) => (v.dom as HTMLInputElement).focus()}
        oninput={(e: Event) => modal.findPeople((e.target as HTMLInputElement).value)}
      />
      <ul className="CorkboardPicker-list">
        {list.map((p) => (
          <li>
            <button onclick={() => ((modal.assigning = false), (modal.peopleQuery = ''), modal.save({ assigneeIds: [...card.assigneeIds, p.id] }))}>
              <Avatar user={p} size={22} /> {p.name}
            </button>
          </li>
        ))}
      </ul>
      <p className="helpText">{t('card.assign_help')}</p>
    </div>
  );
}

function dueField(modal: CardModal, card: Detail): Mithril.Children {
  const when = due(card);
  const dueValue = card.dueAt ? toLocalInputs(card.dueAt, card.dueAllDay) : { date: '', time: '' };
  return (
    <div className="CorkboardField">
      <h4>{t('card.due')}</h4>
      {card.abilities.edit ? (
        <div className="CorkboardDueEditor">
          <input
            className="FormControl"
            type="date"
            aria-label={tx('card.due_date')}
            value={dueValue.date}
            onchange={(e: Event) => modal.saveDue((e.target as HTMLInputElement).value, card.dueAllDay ? '' : dueValue.time || '17:00')}
          />
          {card.dueAt && !card.dueAllDay ? (
            <input
              className="FormControl"
              type="time"
              aria-label={tx('card.due_time')}
              value={dueValue.time}
              onchange={(e: Event) => modal.saveDue(dueValue.date, (e.target as HTMLInputElement).value)}
            />
          ) : null}
          {card.dueAt ? (
            <label className="checkbox">
              <input type="checkbox" checked={card.dueAllDay} onchange={() => modal.saveDue(dueValue.date, card.dueAllDay ? '17:00' : '')} />
              {t('card.all_day')}
            </label>
          ) : null}
          {card.dueAt ? (
            <Button className="Button Button--link" onclick={() => modal.save({ dueAt: null })}>
              {t('card.clear_due')}
            </Button>
          ) : null}
        </div>
      ) : when ? (
        <p className={'CorkboardDue is-' + when.state}>{when.text}</p>
      ) : (
        <p className="CorkboardMuted">{t('card.no_due')}</p>
      )}
    </div>
  );
}

function labels(modal: CardModal, card: Detail): Mithril.Children {
  const edit = card.abilities.edit;
  return (
    <div className="CorkboardField">
      <h4>{t('card.labels')}</h4>
      <div className="CorkboardLabelPicker">
        {modal.attrs.board.data!.labels.map((l) => {
          const on = card.labelIds.includes(l.id);
          return edit ? (
            <button
              className={classList('CorkboardLabel CorkboardLabel--pick', on && 'is-on')}
              style={{ background: l.color, color: onColor(l.color) }}
              aria-pressed={on ? 'true' : 'false'}
              onclick={() => modal.save({ labelIds: on ? card.labelIds.filter((x) => x !== l.id) : [...card.labelIds, l.id] })}
            >
              {on ? <Icon name="fas fa-check" /> : null} {l.name || tx('label.unnamed')}
            </button>
          ) : on ? (
            <span className="CorkboardLabel" style={{ background: l.color, color: onColor(l.color) }}>
              {l.name || ' '}
            </span>
          ) : null;
        })}
      </div>
    </div>
  );
}

function cover(modal: CardModal, card: Detail): Mithril.Children {
  return (
    <div className="CorkboardField">
      <h4>{t('card.cover')}</h4>
      <div className="CorkboardSwatches">
        {COVERS.map((c) => (
          <button
            className={classList('CorkboardSwatch', card.coverColor === c && 'is-on')}
            style={{ background: c }}
            aria-label={c}
            aria-pressed={card.coverColor === c ? 'true' : 'false'}
            onclick={() => modal.save({ coverColor: card.coverColor === c ? null : c })}
          />
        ))}
      </div>
    </div>
  );
}

function actions(modal: CardModal, card: Detail): Mithril.Children {
  const data = modal.attrs.board.data!;
  const edit = card.abilities.edit;
  return (
    <div className="CorkboardCardModal-actions">
      {edit && data.board.status === 'active' ? (
        <Button
          className="Button Button--primary"
          icon={card.completedAt ? 'fas fa-rotate-left' : 'fas fa-check'}
          loading={modal.busy}
          onclick={() => modal.save({ completed: !card.completedAt })}
        >
          {t(card.completedAt ? 'card.reopen' : 'card.complete')}
        </Button>
      ) : null}
      {edit ? (
        <Button
          className="Button"
          icon="fas fa-arrows-up-down-left-right"
          onclick={() => app.modal.show(MoveCardModal, { board: modal.attrs.board, cardId: card.id }, true)}
        >
          {t('card.move')}
        </Button>
      ) : null}
      {app.session.user && data.abilities.edit ? (
        <Button className="Button" icon="far fa-copy" onclick={() => clone(modal, card)}>
          {t('card.clone')}
        </Button>
      ) : null}
      {edit ? (
        <Button className="Button" icon="fas fa-box-archive" onclick={() => modal.archive(card)}>
          {t(card.archivedAt ? 'card.restore' : 'card.archive')}
        </Button>
      ) : null}
      {card.abilities.manage || (edit && app.session.user && Number(app.session.user.id()) === card.ownerId) ? (
        <Button className="Button Button--danger" icon="far fa-trash-can" onclick={() => modal.remove(card)}>
          {t('card.delete')}
        </Button>
      ) : null}
    </div>
  );
}

function clone(modal: CardModal, card: Detail): Promise<void> {
  return call('POST', `/cards/${card.id}/clone`, {}).then((answer) => {
    modal.attrs.board.card(answer);
    app.alerts.show({ type: 'success' }, tx('card.cloned'));
  });
}

/** The date and time inputs' values for a due date (a whole day is read in UTC). */
function toLocalInputs(iso: string, allDay: boolean): { date: string; time: string } {
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, '0');
  if (allDay) return { date: `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`, time: '' };
  return { date: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`, time: `${pad(d.getHours())}:${pad(d.getMinutes())}` };
}
