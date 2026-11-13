import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import { Avatar, due, onColor, t, tx, type SlimCard } from '../util';
import type { BoardPayload } from './BoardState';

export const PRIORITY_CLASS: Record<string, string> = { urgent: 'is-urgent', high: 'is-high', normal: 'is-normal', low: 'is-low' };

/** What a card shows on the board: labels, title, due, priority, checklist, comments, people. */
export const CardFace = {
  view(vnode: Mithril.Vnode<{ card: SlimCard; data: BoardPayload; moving?: string | null }>) {
    const { card, data, moving } = vnode.attrs;
    const labels = card.labelIds.map((id) => data.labels.find((l) => l.id === id)).filter(Boolean) as BoardPayload['labels'];
    const when = due(card);
    return [
      card.coverColor ? <div className="CorkboardCard-cover" style={{ background: card.coverColor }} /> : null,
      moving ? <div className="CorkboardCard-moving">{t('board.moving', { name: moving })}</div> : null,
      labels.length ? (
        <div className="CorkboardCard-labels">
          {labels.map((l) => (
            <span className="CorkboardLabel" style={{ background: l.color, color: onColor(l.color) }} title={l.name}>
              {l.name || ' '}
            </span>
          ))}
        </div>
      ) : null,
      <div className="CorkboardCard-title">
        {card.completedAt ? <Icon name="fas fa-check" className="CorkboardCard-check" /> : null}
        {card.title}
      </div>,
      <div className="CorkboardCard-meta">
        {when ? (
          <span className={'CorkboardDue is-' + when.state}>
            <Icon name="far fa-calendar" />
            {when.text}
          </span>
        ) : null}
        {card.priority !== 'none' ? (
          <span className={'CorkboardPriority ' + PRIORITY_CLASS[card.priority]}>
            <span className="CorkboardPriority-dot" />
            {t('priority.' + card.priority)}
          </span>
        ) : null}
        {card.checklistTotal ? (
          <span
            className={'CorkboardCard-stat' + (card.checklistDone === card.checklistTotal ? ' is-complete' : '')}
            title={tx('card.checklist_count', { done: card.checklistDone, total: card.checklistTotal })}
          >
            <Icon name="far fa-square-check" />
            {card.checklistDone}/{card.checklistTotal}
          </span>
        ) : null}
        {card.commentCount ? (
          <span className="CorkboardCard-stat">
            <Icon name="far fa-comment" />
            {card.commentCount}
          </span>
        ) : null}
        {card.discussionId ? (
          <span className="CorkboardCard-stat" title={tx('card.has_discussion')}>
            <Icon name="fas fa-link" />
          </span>
        ) : null}
        <span className="CorkboardCard-people">
          {card.assigneeIds.slice(0, 3).map((id) => (
            <Avatar user={data.users[id]} size={22} ring />
          ))}
          {card.assigneeIds.length > 3 ? <span className="CorkboardCard-more">+{card.assigneeIds.length - 3}</span> : null}
        </span>
      </div>,
    ];
  },
};
