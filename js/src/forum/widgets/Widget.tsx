import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { boardUrl, due, t, tx } from '../util';
import { fetchWidget } from './data';

export type Kind = 'mine' | 'due' | 'progress';

export interface WidgetAttrs {
  kind: Kind;
  host: 'fof' | 'page-builder';
  settings?: Record<string, any>;
  /** FoF draws its own box and title. */
  bare?: boolean;
}

const PRIORITY: Record<string, string> = { urgent: 'is-urgent', high: 'is-high', normal: 'is-normal', low: 'is-low' };

/**
 * The three widgets, one component in every host. My cards and Due soon are the viewer's own
 * (guests get nothing: no box). Board progress shows one board to whoever may see it.
 */
export default class CorkboardWidget extends Component<WidgetAttrs> {
  view() {
    const { kind, settings = {}, bare } = this.attrs;
    if (kind !== 'progress' && !app.session.user) return null;
    const body = kind === 'mine' ? this.mine(settings) : kind === 'due' ? this.due(settings) : this.progress(settings);
    if (body === null) return null;
    return (
      <div className={classList('CorkboardWidget', `CorkboardWidget--${kind}`, bare && 'CorkboardWidget--bare')}>
        {bare ? null : (
          <h2 className="CorkboardWidget-title">
            <Icon name={kind === 'mine' ? 'fas fa-id-card' : kind === 'due' ? 'fas fa-clock' : 'fas fa-bars-progress'} /> {t(`widget.${kind}_title`)}
          </h2>
        )}
        {body}
      </div>
    );
  }

  mine(s: Record<string, any>): Mithril.Children {
    const { data, loading } = fetchWidget('/my-cards', { limit: Math.max(1, Math.min(10, Number(s.count) || 5)), owned: s.owned ? 1 : undefined });
    if (loading) return <LoadingIndicator size="small" />;
    if (!data || data.error) return null;
    return [
      data.cards.length ? (
        <ul className="CorkboardWidget-list">{data.cards.map((c: any) => this.row(c, `${c.board} · ${c.column}`))}</ul>
      ) : (
        <p className="CorkboardWidget-empty">{t('widget.mine_empty')}</p>
      ),
      <Link className="CorkboardWidget-more" href={app.route('corkboard.mine')}>
        {data.total > data.cards.length ? t('widget.see_all', { count: data.total }) : t('widget.open_mine')}
      </Link>,
    ];
  }

  due(s: Record<string, any>): Mithril.Children {
    const board = String(s.board || '').replace(/\D/g, '');
    const { data, loading } = fetchWidget('/due-soon', { days: Number(s.days) || 3, limit: Math.max(1, Math.min(10, Number(s.count) || 5)), board: board || undefined });
    if (loading) return <LoadingIndicator size="small" />;
    if (!data || data.error) return null;
    if (!data.cards.length) return <p className="CorkboardWidget-empty">{t('widget.due_empty')}</p>;
    return (
      <ul className="CorkboardWidget-list">
        {data.cards.map((c: any) => {
          const names = (c.assigneeIds || []).map((id: number) => data.users[id]?.name).filter(Boolean);
          return this.row(c, [board ? '' : c.board, names.length ? names.join(', ') : ''].filter(Boolean).join(' · '));
        })}
      </ul>
    );
  }

  progress(s: Record<string, any>): Mithril.Children {
    const id = String(s.board || '').replace(/\D/g, '');
    if (!id) return app.session.user && app.forum.attribute('corkboardCanModerate') ? <p className="CorkboardWidget-empty">{t('widget.progress_pick')}</p> : null;
    const { data, loading } = fetchWidget(`/progress/${id}`, {});
    if (loading) return <LoadingIndicator size="small" />;
    if (!data || data.error) return null;
    const total = data.columns.reduce((n: number, c: any) => n + c.count, 0);
    const done = data.columns.filter((c: any) => c.isDone).reduce((n: number, c: any) => n + c.count, 0);
    return [
      <Link className="CorkboardWidget-board" href={boardUrl(data.board)}>
        {data.board.title}
      </Link>,
      <div className="CorkboardStack" role="img" aria-label={tx('list.progress', { done, total })}>
        {data.columns.map((c: any, i: number) =>
          c.count ? <span className={classList('CorkboardStack-part', c.isDone && 'is-done')} style={{ flexGrow: c.count, opacity: c.isDone ? 1 : 0.35 + (0.5 * i) / Math.max(1, data.columns.length) }} title={`${c.title}: ${c.count}`} /> : null
        )}
      </div>,
      <p className="CorkboardWidget-summary">{t('list.progress', { done, total })}</p>,
      <ul className="CorkboardWidget-legend">
        {data.columns.map((c: any) => (
          <li>
            {c.title} <strong>{c.count}</strong>
          </li>
        ))}
      </ul>,
      s.next !== false && data.next.length ? <ul className="CorkboardWidget-list">{data.next.map((c: any) => this.row({ ...c, boardId: data.board.id, boardSlug: data.board.slug }, null))}</ul> : null,
    ];
  }

  row(c: any, sub: string | null): Mithril.Children {
    const when = due(c);
    return (
      <li className="CorkboardWidget-item">
        <Link href={boardUrl({ id: c.boardId, slug: c.boardSlug }, c.id)}>
          <span className="CorkboardWidget-itemTitle">
            {c.priority && c.priority !== 'none' ? <span className={'CorkboardPriority-dot ' + PRIORITY[c.priority]} /> : null}
            {c.title}
          </span>
          <span className="CorkboardWidget-itemMeta">
            {sub ? <span className="CorkboardWidget-where">{sub}</span> : null}
            {when ? <span className={'CorkboardDue is-' + when.state}>{when.text}</span> : null}
          </span>
        </Link>
      </li>
    );
  }
}
