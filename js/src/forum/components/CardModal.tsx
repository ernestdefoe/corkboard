import app from 'flarum/forum/app';
import Modal, { type IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { call, read, t, tx, write, type Person } from '../util';
import type { BoardState } from './BoardState';
import ActivityList from './ActivityList';
import type { Detail } from './card/types';
import { checklist, discussion } from './card/sections';
import { comments } from './card/comments';
import { side } from './card/side';

export interface CardModalAttrs extends IInternalModalAttrs {
  /** Not `state`: core gives every modal its own `state` (the modal manager). */
  board: BoardState;
  cardId: number;
  onclose?: () => void;
}

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
    return read<Detail>('cards', this.attrs.cardId)
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

  /** Core focuses the first field, which on a card is far down ("Add an item"): the dialog opened scrolled. */
  onready() {}

  /** PATCH, then show the change on the board and in here. */
  save(changes: Record<string, any>): Promise<void> {
    this.busy = true;
    return write('PATCH', 'cards', this.attrs.cardId, changes)
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
    return (
      <div className="Modal-body CorkboardCardModal-body">
        {this.top(card)}
        {/* Phones: core puts the dialog's title in the header bar, which a coloured header can hide. */}
        <h2 className="CorkboardCardModal-phoneTitle">{card.title}</h2>
        {this.titleControl(card)}
        <div className="CorkboardCardModal-columns">
          <div className="CorkboardCardModal-main">
            {this.description(card)}
            {checklist(this, card)}
            {discussion(this, card)}
            {this.conversation(card)}
          </div>
          <aside className="CorkboardCardModal-side">{side(this, card)}</aside>
        </div>
      </div>
    );
  }

  /** Where the card is, who added it, and the watch button. */
  top(card: Detail): Mithril.Children {
    const data = this.attrs.board.data!;
    const column = data.columns.find((c) => c.id === card.columnId);
    const owner = card.users[card.ownerId!] || data.users[card.ownerId!];
    return (
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
    );
  }

  /** Editors rename the card in place. */
  titleControl(card: Detail): Mithril.Children {
    const edit = card.abilities.edit;
    return edit && this.editingTitle ? (
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
    ) : null;
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
        {this.tab === 'activity' ? <ActivityList state={this.attrs.board} cardId={card.id} /> : comments(this, card)}
      </section>
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
    write('PATCH', 'cards', card.id, { archived: archive }).then((answer) => {
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
    write('DELETE', 'cards', card.id).then(() => {
      this.attrs.board.removeCard(card.id);
      this.hide();
    });
  }
}
