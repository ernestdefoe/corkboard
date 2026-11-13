import app from 'flarum/forum/app';
import Modal, { type IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { ago, Avatar, call, due, onColor, PRIORITIES, t, tx, type Person, type SlimCard } from '../util';
import type { BoardState } from './BoardState';
import MoveCardModal from './MoveCardModal';
import ActivityList from './ActivityList';

interface Detail extends SlimCard {
  boardId: number;
  archivedAt: string | null;
  completedBy: number | null;
  createdAt: string | null;
  descriptionHtml: string | null;
  description: string | null;
  checklist: Array<{ id: number; text: string; isDone: boolean; userId: number | null }>;
  comments: Array<{
    id: number;
    userId: number | null;
    html: string;
    source: string | null;
    createdAt: string;
    editedAt: string | null;
    canEdit: boolean;
    canDelete: boolean;
  }>;
  commentsMore: boolean;
  watching: boolean | null;
  watcherCount: number;
  discussion: null | {
    id: number;
    title: string;
    slug: string;
    replyCount: number;
    lastReplies: Array<{ id: number; number: number; createdAt: string; user: Person | null; excerpt: string | null }>;
  };
  users: Record<string, Person>;
  abilities: { comment: boolean; edit: boolean; manage: boolean };
}

export interface CardModalAttrs extends IInternalModalAttrs {
  /** Not `state`: core gives every modal its own `state` (the modal manager). */
  board: BoardState;
  cardId: number;
  onclose?: () => void;
}

const COVERS = ['#1971c2', '#2b8a3e', '#f2c94c', '#c2410c', '#c92a2a', '#6741d9', '#0b7285'];

/**
 * A card, opened: description, checklist, the linked discussion and its last replies, comments
 * and history on the left; people, priority, due date, labels, column and actions on the right.
 * Editors change everything in place; commenters can comment; viewers read.
 */
export default class CardModal extends Modal<CardModalAttrs> {
  static isDismissibleViaBackdropClick = true;

  card: Detail | null = null;
  failed = false;
  editingTitle = false;
  titleDraft = '';
  editingDescription = false;
  descriptionDraft = '';
  newItem = '';
  comment = '';
  editingComment: number | null = null;
  commentDraft = '';
  tab: 'comments' | 'activity' = 'comments';
  assigning = false;
  people: Person[] = [];
  peopleQuery = '';
  linking = false;
  found: Array<{ id: string; title: string }> = [];
  busy = false;
  private searchTimer = 0;

  oninit(vnode: Mithril.Vnode<CardModalAttrs, this>) {
    super.oninit(vnode);
    this.load();
  }

  onremove(vnode: Mithril.VnodeDOM<CardModalAttrs, this>) {
    super.onremove(vnode);
    this.attrs.onclose?.();
  }

  load(): Promise<void> {
    return call<Detail>('GET', `/cards/${this.attrs.cardId}`, undefined, undefined, true)
      .then((card) => {
        this.card = card;
        Object.assign(this.attrs.board.data?.users || {}, card.users);
      })
      .catch(() => (this.failed = true))
      .finally(() => m.redraw());
  }

  className() {
    return 'CorkboardCardModal Modal--large';
  }

  title() {
    return this.card ? this.card.title : tx('card.loading');
  }

  /** PATCH, then show the change on the board and in here. */
  save(changes: Record<string, any>): Promise<void> {
    this.busy = true;
    return call('PATCH', `/cards/${this.attrs.cardId}`, changes)
      .then((answer) => {
        this.attrs.board.card(answer);
        return this.load();
      })
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }

  content() {
    if (this.failed) return <div className="Modal-body">{t('error.gone')}</div>;
    const card = this.card;
    if (!card)
      return (
        <div className="Modal-body">
          <LoadingIndicator />
        </div>
      );
    const data = this.attrs.board.data!;
    const column = data.columns.find((c) => c.id === card.columnId);
    const edit = card.abilities.edit;
    const owner = card.users[card.ownerId!] || data.users[card.ownerId!];

    return (
      <div className="Modal-body CorkboardCardModal-body">
        <div className="CorkboardCardModal-top">
          <span className="CorkboardCardModal-crumb">
            {data.board.title} › <strong>{column?.title}</strong>
            {owner && card.createdAt ? <> · {t('card.added_by', { name: owner.name, date: new Date(card.createdAt).toLocaleDateString() })}</> : null}
          </span>
          {card.archivedAt ? <span className="CorkboardChip">{t('card.archived')}</span> : null}
          {card.watching !== null ? (
            <Button
              className={classList('Button', card.watching && 'Button--primary')}
              icon="far fa-eye"
              aria-pressed={card.watching ? 'true' : 'false'}
              onclick={() => this.save({ watching: !card.watching })}
            >
              {t(card.watching ? 'card.watching' : 'card.watch', { count: card.watcherCount })}
            </Button>
          ) : null}
        </div>
        {edit && this.editingTitle ? (
          <input
            className="FormControl CorkboardCardModal-titleInput"
            value={this.titleDraft}
            maxlength={160}
            aria-label={tx('card.title')}
            oncreate={(v: Mithril.VnodeDOM) => (v.dom as HTMLInputElement).focus()}
            oninput={(e: Event) => (this.titleDraft = (e.target as HTMLInputElement).value)}
            onkeydown={(e: KeyboardEvent) => {
              if (e.key === 'Enter') this.saveTitle();
              if (e.key === 'Escape') {
                e.stopPropagation();
                this.editingTitle = false;
              }
            }}
            onblur={() => this.saveTitle()}
          />
        ) : edit ? (
          <button className="CorkboardCardModal-titleButton" onclick={() => ((this.editingTitle = true), (this.titleDraft = card.title))}>
            {t('card.rename')}
          </button>
        ) : null}
        <div className="CorkboardCardModal-columns">
          <div className="CorkboardCardModal-main">
            {this.description(card)}
            {this.checklist(card)}
            {this.discussion(card)}
            {this.conversation(card)}
          </div>
          <aside className="CorkboardCardModal-side">{this.side(card)}</aside>
        </div>
      </div>
    );
  }

  saveTitle(): void {
    const title = this.titleDraft.trim();
    this.editingTitle = false;
    if (title && this.card && title !== this.card.title) this.save({ title });
  }

  description(card: Detail): Mithril.Children {
    const edit = card.abilities.edit;
    return (
      <section className="CorkboardCardModal-section">
        <div className="CorkboardCardModal-sectionHead">
          <h3>{t('card.description')}</h3>
          {edit && !this.editingDescription ? (
            <Button
              className="Button Button--link"
              onclick={() => ((this.editingDescription = true), (this.descriptionDraft = card.description || ''))}
            >
              {t('card.edit')}
            </Button>
          ) : null}
        </div>
        {this.editingDescription ? (
          <div className="CorkboardCardModal-editor">
            <textarea
              className="FormControl"
              rows={6}
              aria-label={tx('card.description')}
              value={this.descriptionDraft}
              oncreate={(v: Mithril.VnodeDOM) => (v.dom as HTMLTextAreaElement).focus()}
              oninput={(e: Event) => (this.descriptionDraft = (e.target as HTMLTextAreaElement).value)}
            />
            <p className="helpText">{t('card.markdown_help')}</p>
            <div className="CorkboardCardModal-editorActions">
              <Button
                className="Button Button--primary"
                loading={this.busy}
                onclick={() => this.save({ description: this.descriptionDraft }).then(() => (this.editingDescription = false))}
              >
                {t('save')}
              </Button>
              <Button className="Button Button--link" onclick={() => (this.editingDescription = false)}>
                {t('cancel')}
              </Button>
            </div>
          </div>
        ) : card.descriptionHtml ? (
          <div className="CorkboardCardModal-description Post-body">{m.trust(card.descriptionHtml)}</div>
        ) : (
          <p className="CorkboardCardModal-none">{t(edit ? 'card.no_description_edit' : 'card.no_description')}</p>
        )}
      </section>
    );
  }

  checklist(card: Detail): Mithril.Children {
    const edit = card.abilities.edit;
    if (!card.checklist.length && !edit) return null;
    const done = card.checklist.filter((i) => i.isDone).length;
    const total = card.checklist.length;
    const add = () => {
      const text = this.newItem.trim();
      if (!text) return;
      this.newItem = '';
      call('POST', `/cards/${card.id}/checklist`, { text }).then((a) => this.checklistChanged(a));
    };
    return (
      <section className="CorkboardCardModal-section">
        <div className="CorkboardCardModal-sectionHead">
          <h3>{t('card.checklist')}</h3>
          {total ? <span className="CorkboardCardModal-count">{t('card.checklist_count', { done, total })}</span> : null}
          {total ? (
            <div className="CorkboardProgress" role="progressbar" aria-valuemin="0" aria-valuemax={total} aria-valuenow={done}>
              <div className="CorkboardProgress-bar" style={{ width: `${Math.round((done / total) * 100)}%` }} />
            </div>
          ) : null}
        </div>
        <ul className="CorkboardChecklist">
          {card.checklist.map((item) => (
            <li className={classList('CorkboardChecklist-item', item.isDone && 'is-done')} key={item.id}>
              <label>
                <input
                  type="checkbox"
                  checked={item.isDone}
                  disabled={!edit}
                  onchange={() => call('PATCH', `/checklist/${item.id}`, { isDone: !item.isDone }).then((a) => this.checklistChanged(a))}
                />
                <span>{item.text}</span>
              </label>
              {edit ? (
                <Button
                  className="Button Button--icon Button--link"
                  icon="fas fa-xmark"
                  aria-label={tx('card.remove_item', { text: item.text })}
                  onclick={() => call('DELETE', `/checklist/${item.id}`).then(() => this.load().then(() => this.syncCounts()))}
                />
              ) : null}
            </li>
          ))}
        </ul>
        {edit ? (
          <div className="CorkboardCardModal-inline">
            <input
              className="FormControl"
              placeholder={tx('card.add_item')}
              aria-label={tx('card.add_item')}
              maxlength={300}
              value={this.newItem}
              oninput={(e: Event) => (this.newItem = (e.target as HTMLInputElement).value)}
              onkeydown={(e: KeyboardEvent) => {
                if (e.key === 'Enter') add();
              }}
            />
            <Button className="Button" onclick={add}>
              {t('card.add')}
            </Button>
          </div>
        ) : null}
      </section>
    );
  }

  checklistChanged(answer: any): void {
    if (!this.card) return;
    this.card.checklist = answer.checklist;
    this.syncCounts(answer);
    m.redraw();
  }

  syncCounts(answer?: any): void {
    const slim = this.attrs.board.data?.cards.find((c) => c.id === this.attrs.cardId);
    if (!slim || !this.card) return;
    slim.checklistDone = answer?.checklistDone ?? this.card.checklist.filter((i) => i.isDone).length;
    slim.checklistTotal = answer?.checklistTotal ?? this.card.checklist.length;
    if (answer?.version) this.attrs.board.seen(answer.version);
  }

  discussion(card: Detail): Mithril.Children {
    const d = card.discussion;
    const edit = card.abilities.edit;
    if (d) {
      return (
        <section className="CorkboardCardModal-section CorkboardThread">
          <div className="CorkboardThread-head">
            <Icon name="fas fa-link" />
            <Link href={app.route.discussion({ id: () => String(d.id), slug: () => d.slug } as any)}>{d.title}</Link>
            <span className="CorkboardThread-count">{t('card.replies', { count: d.replyCount })}</span>
            {edit ? (
              <Button className="Button Button--link" onclick={() => this.save({ discussionId: null })}>
                {t('card.unlink')}
              </Button>
            ) : null}
          </div>
          {d.lastReplies.map((r) => (
            <div className="CorkboardThread-reply">
              <Avatar user={r.user} size={20} />
              <div>
                <strong>{r.user ? r.user.name : tx('deleted_user')}</strong> <span className="CorkboardMuted">{ago(r.createdAt)}</span> {r.excerpt}
              </div>
            </div>
          ))}
          <Link className="CorkboardThread-more" href={app.route.discussion({ id: () => String(d.id), slug: () => d.slug } as any, d.replyCount + 1)}>
            {t('card.continue')}
          </Link>
        </section>
      );
    }
    if (card.discussionId) return null;
    if (!edit) return null;
    return (
      <section className="CorkboardCardModal-section">
        <div className="CorkboardCardModal-sectionHead">
          <h3>{t('card.discussion')}</h3>
        </div>
        {this.linking ? (
          <div className="CorkboardPicker">
            <input
              className="FormControl"
              type="search"
              placeholder={tx('card.find_discussion')}
              aria-label={tx('card.find_discussion')}
              oncreate={(v: Mithril.VnodeDOM) => (v.dom as HTMLInputElement).focus()}
              oninput={(e: Event) => this.findDiscussions((e.target as HTMLInputElement).value)}
            />
            <ul className="CorkboardPicker-list">
              {this.found.map((f) => (
                <li>
                  <button onclick={() => ((this.linking = false), this.save({ discussionId: Number(f.id) }))}>{f.title}</button>
                </li>
              ))}
            </ul>
          </div>
        ) : (
          <div className="CorkboardCardModal-buttons">
            <Button className="Button" icon="fas fa-link" onclick={() => (this.linking = true)}>
              {t('card.link_discussion')}
            </Button>
            {app.forum.attribute('canStartDiscussion') ? (
              <Button className="Button" icon="fas fa-comments" loading={this.busy} onclick={() => this.startDiscussion()}>
                {t('card.start_discussion')}
              </Button>
            ) : null}
          </div>
        )}
      </section>
    );
  }

  findDiscussions(q: string): void {
    clearTimeout(this.searchTimer);
    if (q.trim().length < 2) {
      this.found = [];
      return;
    }
    this.searchTimer = window.setTimeout(() => {
      app.store.find<any[]>('discussions', { filter: { q }, page: { limit: 6 } } as any).then((list: any) => {
        this.found = [...list].map((d: any) => ({ id: d.id(), title: d.title() }));
        m.redraw();
      });
    }, 250);
  }

  startDiscussion(): void {
    this.busy = true;
    call('POST', `/cards/${this.attrs.cardId}/discussion`)
      .then((answer) => {
        this.attrs.board.card(answer);
        app.alerts.show({ type: 'success' }, tx('card.discussion_started'));
        return this.load();
      })
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }

  conversation(card: Detail): Mithril.Children {
    return (
      <section className="CorkboardCardModal-section">
        <div className="CorkboardTabs" role="tablist">
          <button
            role="tab"
            aria-selected={this.tab === 'comments' ? 'true' : 'false'}
            className={classList(this.tab === 'comments' && 'is-active')}
            onclick={() => (this.tab = 'comments')}
          >
            {t('card.comments', { count: card.commentCount })}
          </button>
          <button
            role="tab"
            aria-selected={this.tab === 'activity' ? 'true' : 'false'}
            className={classList(this.tab === 'activity' && 'is-active')}
            onclick={() => (this.tab = 'activity')}
          >
            {t('card.activity')}
          </button>
        </div>
        {this.tab === 'activity' ? <ActivityList state={this.attrs.board} cardId={card.id} /> : this.comments(card)}
      </section>
    );
  }

  comments(card: Detail): Mithril.Children {
    const me = app.session.user;
    const send = () => {
      const content = this.comment.trim();
      if (!content) return;
      this.busy = true;
      call('POST', `/cards/${card.id}/comments`, { content })
        .then((answer) => {
          this.comment = '';
          card.comments = answer.comments;
          card.commentsMore = answer.more;
          Object.assign(card.users, answer.users);
          card.commentCount = answer.commentCount;
          const slim = this.attrs.board.data?.cards.find((c) => c.id === card.id);
          if (slim) slim.commentCount = answer.commentCount;
          this.attrs.board.seen(answer.version);
        })
        .finally(() => {
          this.busy = false;
          m.redraw();
        });
    };
    return (
      <div className="CorkboardComments">
        {card.abilities.comment && me ? (
          <div className="CorkboardComments-composer">
            <Avatar
              user={{ id: Number(me.id()), name: me.displayName(), username: me.username(), slug: me.slug(), avatarUrl: me.avatarUrl() || null }}
              size={30}
            />
            <div className="CorkboardComments-field">
              <textarea
                className="FormControl"
                rows={2}
                placeholder={tx('card.comment_placeholder')}
                aria-label={tx('card.comment_placeholder')}
                value={this.comment}
                oninput={(e: Event) => (this.comment = (e.target as HTMLTextAreaElement).value)}
                onkeydown={(e: KeyboardEvent) => {
                  if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) send();
                }}
              />
              {this.comment.trim() ? (
                <Button className="Button Button--primary" loading={this.busy} onclick={send}>
                  {t('card.send')}
                </Button>
              ) : null}
            </div>
          </div>
        ) : null}
        {card.comments.map((c) => {
          const user = card.users[c.userId!];
          return (
            <div className="CorkboardComment" key={c.id}>
              <Avatar user={user} size={30} />
              <div className="CorkboardComment-body">
                <div className="CorkboardComment-head">
                  <strong>{user ? user.name : tx('deleted_user')}</strong> <span className="CorkboardMuted">{ago(c.createdAt)}</span>
                  {c.editedAt ? <span className="CorkboardMuted"> · {t('card.edited')}</span> : null}
                  {c.canEdit && this.editingComment !== c.id ? (
                    <Button className="Button Button--link" onclick={() => ((this.editingComment = c.id), (this.commentDraft = c.source || ''))}>
                      {t('card.edit')}
                    </Button>
                  ) : null}
                  {c.canDelete ? (
                    <Button
                      className="Button Button--link"
                      onclick={() => {
                        if (confirm(tx('card.delete_comment_confirm'))) call('DELETE', `/comments/${c.id}`).then(() => this.load());
                      }}
                    >
                      {t('card.delete')}
                    </Button>
                  ) : null}
                </div>
                {this.editingComment === c.id ? (
                  <div className="CorkboardCardModal-editor">
                    <textarea
                      className="FormControl"
                      rows={3}
                      aria-label={tx('card.edit')}
                      value={this.commentDraft}
                      oninput={(e: Event) => (this.commentDraft = (e.target as HTMLTextAreaElement).value)}
                    />
                    <div className="CorkboardCardModal-editorActions">
                      <Button
                        className="Button Button--primary"
                        onclick={() =>
                          call('PATCH', `/comments/${c.id}`, { content: this.commentDraft }).then((answer) => {
                            card.comments = answer.comments;
                            this.editingComment = null;
                            m.redraw();
                          })
                        }
                      >
                        {t('save')}
                      </Button>
                      <Button className="Button Button--link" onclick={() => (this.editingComment = null)}>
                        {t('cancel')}
                      </Button>
                    </div>
                  </div>
                ) : (
                  <div className="CorkboardComment-text Post-body">{m.trust(c.html)}</div>
                )}
              </div>
            </div>
          );
        })}
        {!card.comments.length ? <p className="CorkboardCardModal-none">{t('card.no_comments')}</p> : null}
        {card.commentsMore ? (
          <Button
            className="Button Button--link"
            onclick={() =>
              call('GET', `/cards/${card.id}/comments`, undefined, { before: card.comments[card.comments.length - 1].id }).then((answer) => {
                card.comments = [...card.comments, ...answer.comments];
                card.commentsMore = answer.more;
                Object.assign(card.users, answer.users);
                m.redraw();
              })
            }
          >
            {t('card.older_comments')}
          </Button>
        ) : null}
      </div>
    );
  }

  side(card: Detail): Mithril.Children {
    const data = this.attrs.board.data!;
    const edit = card.abilities.edit;
    const users = { ...data.users, ...card.users };
    const when = due(card);
    const dueValue = card.dueAt ? toLocalInputs(card.dueAt, card.dueAllDay) : { date: '', time: '' };

    return [
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
                  onclick={() => this.save({ assigneeIds: card.assigneeIds.filter((x) => x !== id) })}
                />
              ) : null}
            </li>
          ))}
        </ul>
        {!card.assigneeIds.length ? <p className="CorkboardMuted">{t('card.nobody')}</p> : null}
        {edit ? this.assignPicker(card) : null}
      </div>,
      <div className="CorkboardField">
        <h4>{t('card.priority')}</h4>
        {edit ? (
          <Select
            aria-label={tx('card.priority')}
            value={card.priority}
            options={Object.fromEntries(PRIORITIES.map((p) => [p, tx('priority.' + p)]))}
            onchange={(value: string) => this.save({ priority: value })}
          />
        ) : (
          <p>{t('priority.' + card.priority)}</p>
        )}
      </div>,
      <div className="CorkboardField">
        <h4>{t('card.due')}</h4>
        {edit ? (
          <div className="CorkboardDueEditor">
            <input
              className="FormControl"
              type="date"
              aria-label={tx('card.due_date')}
              value={dueValue.date}
              onchange={(e: Event) => this.saveDue((e.target as HTMLInputElement).value, card.dueAllDay ? '' : dueValue.time || '17:00')}
            />
            {card.dueAt && !card.dueAllDay ? (
              <input
                className="FormControl"
                type="time"
                aria-label={tx('card.due_time')}
                value={dueValue.time}
                onchange={(e: Event) => this.saveDue(dueValue.date, (e.target as HTMLInputElement).value)}
              />
            ) : null}
            {card.dueAt ? (
              <label className="checkbox">
                <input type="checkbox" checked={card.dueAllDay} onchange={() => this.saveDue(dueValue.date, card.dueAllDay ? '17:00' : '')} />
                {t('card.all_day')}
              </label>
            ) : null}
            {card.dueAt ? (
              <Button className="Button Button--link" onclick={() => this.save({ dueAt: null })}>
                {t('card.clear_due')}
              </Button>
            ) : null}
          </div>
        ) : when ? (
          <p className={'CorkboardDue is-' + when.state}>{when.text}</p>
        ) : (
          <p className="CorkboardMuted">{t('card.no_due')}</p>
        )}
      </div>,
      data.labels.length ? (
        <div className="CorkboardField">
          <h4>{t('card.labels')}</h4>
          <div className="CorkboardLabelPicker">
            {data.labels.map((l) => {
              const on = card.labelIds.includes(l.id);
              return edit ? (
                <button
                  className={classList('CorkboardLabel CorkboardLabel--pick', on && 'is-on')}
                  style={{ background: l.color, color: onColor(l.color) }}
                  aria-pressed={on ? 'true' : 'false'}
                  onclick={() => this.save({ labelIds: on ? card.labelIds.filter((x) => x !== l.id) : [...card.labelIds, l.id] })}
                >
                  {on ? <Icon name="fas fa-check" /> : null} {l.name || tx('label.unnamed')}
                </button>
              ) : on ? (
                <span className="CorkboardLabel" style={{ background: l.color, color: onColor(l.color) }}>
                  {l.name || ' '}
                </span>
              ) : null;
            })}
          </div>
        </div>
      ) : null,
      edit ? (
        <div className="CorkboardField">
          <h4>{t('card.column')}</h4>
          <Select
            aria-label={tx('card.column')}
            value={String(card.columnId)}
            options={Object.fromEntries(data.columns.map((c) => [String(c.id), c.title]))}
            onchange={(value: string) => this.save({ columnId: Number(value) })}
          />
        </div>
      ) : null,
      edit ? (
        <div className="CorkboardField">
          <h4>{t('card.cover')}</h4>
          <div className="CorkboardSwatches">
            {COVERS.map((c) => (
              <button
                className={classList('CorkboardSwatch', card.coverColor === c && 'is-on')}
                style={{ background: c }}
                aria-label={c}
                aria-pressed={card.coverColor === c ? 'true' : 'false'}
                onclick={() => this.save({ coverColor: card.coverColor === c ? null : c })}
              />
            ))}
          </div>
        </div>
      ) : null,
      <div className="CorkboardCardModal-actions">
        {edit && data.board.status === 'active' ? (
          <Button
            className="Button Button--primary"
            icon={card.completedAt ? 'fas fa-rotate-left' : 'fas fa-check'}
            loading={this.busy}
            onclick={() => this.save({ completed: !card.completedAt })}
          >
            {t(card.completedAt ? 'card.reopen' : 'card.complete')}
          </Button>
        ) : null}
        {edit ? (
          <Button
            className="Button"
            icon="fas fa-arrows-up-down-left-right"
            onclick={() => app.modal.show(MoveCardModal, { board: this.attrs.board, cardId: card.id }, true)}
          >
            {t('card.move')}
          </Button>
        ) : null}
        {app.session.user && data.abilities.edit ? (
          <Button
            className="Button"
            icon="far fa-copy"
            onclick={() =>
              call('POST', `/cards/${card.id}/clone`, {}).then((answer) => {
                this.attrs.board.card(answer);
                app.alerts.show({ type: 'success' }, tx('card.cloned'));
              })
            }
          >
            {t('card.clone')}
          </Button>
        ) : null}
        {edit ? (
          <Button className="Button" icon="fas fa-box-archive" onclick={() => this.archive(card)}>
            {t(card.archivedAt ? 'card.restore' : 'card.archive')}
          </Button>
        ) : null}
        {card.abilities.manage || (edit && app.session.user && Number(app.session.user.id()) === card.ownerId) ? (
          <Button className="Button Button--danger" icon="far fa-trash-can" onclick={() => this.remove(card)}>
            {t('card.delete')}
          </Button>
        ) : null}
      </div>,
    ];
  }

  assignPicker(card: Detail): Mithril.Children {
    if (!this.assigning) {
      return (
        <Button className="Button Button--link" icon="fas fa-user-plus" onclick={() => (this.assigning = true)}>
          {t('card.assign')}
        </Button>
      );
    }
    const data = this.attrs.board.data!;
    const team = [data.board.ownerId, ...data.members.filter((member) => member.userId).map((member) => member.userId)]
      .filter((id, i, a) => id && a.indexOf(id) === i && !card.assigneeIds.includes(id as number))
      .map((id) => data.users[id as number])
      .filter(Boolean) as Person[];
    const list = this.peopleQuery ? this.people.filter((p) => !card.assigneeIds.includes(p.id)) : team;
    return (
      <div className="CorkboardPicker">
        <input
          className="FormControl"
          type="search"
          placeholder={tx('card.find_person')}
          aria-label={tx('card.find_person')}
          oncreate={(v: Mithril.VnodeDOM) => (v.dom as HTMLInputElement).focus()}
          oninput={(e: Event) => this.findPeople((e.target as HTMLInputElement).value)}
        />
        <ul className="CorkboardPicker-list">
          {list.map((p) => (
            <li>
              <button onclick={() => ((this.assigning = false), (this.peopleQuery = ''), this.save({ assigneeIds: [...card.assigneeIds, p.id] }))}>
                <Avatar user={p} size={22} /> {p.name}
              </button>
            </li>
          ))}
        </ul>
        <p className="helpText">{t('card.assign_help')}</p>
      </div>
    );
  }

  findPeople(q: string): void {
    this.peopleQuery = q.trim();
    clearTimeout(this.searchTimer);
    if (!this.peopleQuery) return;
    this.searchTimer = window.setTimeout(() => {
      call<{ users: Person[] }>('GET', '/people', undefined, { q: this.peopleQuery }, true).then((a) => {
        this.people = a.users;
        m.redraw();
      });
    }, 200);
  }

  saveDue(date: string, time: string): void {
    if (!date) {
      this.save({ dueAt: null });
      return;
    }
    // A whole day is kept at 17:00 UTC, shown as the date everywhere; a time is the viewer's own.
    const dueAt = time ? new Date(`${date}T${time}`).toISOString() : `${date}T17:00:00Z`;
    this.save({ dueAt, dueAllDay: !time });
  }

  archive(card: Detail): void {
    const archive = !card.archivedAt;
    call('PATCH', `/cards/${card.id}`, { archived: archive }).then((answer) => {
      if (archive) {
        this.attrs.board.removeCard(card.id);
        this.attrs.board.seen(answer.version);
        app.alerts.show({ type: 'success' }, tx('card.archived_alert'));
        this.hide();
      } else {
        this.attrs.board.card(answer);
        this.load();
      }
    });
  }

  remove(card: Detail): void {
    if (!confirm(tx('card.delete_confirm', { title: card.title }))) return;
    call('DELETE', `/cards/${card.id}`).then(() => {
      this.attrs.board.removeCard(card.id);
      this.hide();
    });
  }
}

/** The date and time inputs' values for a due date (a whole day is read in UTC). */
function toLocalInputs(iso: string, allDay: boolean): { date: string; time: string } {
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, '0');
  if (allDay) return { date: `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`, time: '' };
  return { date: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`, time: `${pad(d.getHours())}:${pad(d.getMinutes())}` };
}
