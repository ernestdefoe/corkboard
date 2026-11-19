import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { ago, Avatar, t, tx, write } from '../../util';
import type CardModal from '../CardModal';
import type { ChecklistItem, Detail, LinkedDiscussion } from './types';

/** The checklist: progress, one row per item, and the field that adds one. */
export function checklist(modal: CardModal, card: Detail): Mithril.Children {
  const edit = card.abilities.edit;
  if (!card.checklist.length && !edit) return null;
  const done = card.checklist.filter((i) => i.isDone).length;
  const total = card.checklist.length;
  const add = () => {
    const text = modal.newItem.trim();
    if (!text) return;
    modal.newItem = '';
    write('POST', 'checklist-items', null, { cardId: card.id, text }).then((a) => modal.checklistChanged(a));
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
      <ul className="CorkboardChecklist">{card.checklist.map((item) => checklistItem(modal, item, edit))}</ul>
      {edit ? checklistAdder(modal, add) : null}
    </section>
  );
}

function checklistItem(modal: CardModal, item: ChecklistItem, edit: boolean): Mithril.Children {
  return (
    <li className={classList('CorkboardChecklist-item', item.isDone && 'is-done')} key={item.id}>
      <label>
        <input
          type="checkbox"
          checked={item.isDone}
          disabled={!edit}
          onchange={() => write('PATCH', 'checklist-items', item.id, { isDone: !item.isDone }).then((a) => modal.checklistChanged(a))}
        />
        <span>{item.text}</span>
      </label>
      {edit ? (
        <Button
          className="Button Button--icon Button--link"
          icon="fas fa-xmark"
          aria-label={tx('card.remove_item', { text: item.text })}
          onclick={() => write('DELETE', 'checklist-items', item.id).then(() => modal.load().then(() => modal.syncCounts()))}
        />
      ) : null}
    </li>
  );
}

function checklistAdder(modal: CardModal, add: () => void): Mithril.Children {
  return (
    <div className="CorkboardCardModal-inline">
      <input
        className="FormControl"
        placeholder={tx('card.add_item')}
        aria-label={tx('card.add_item')}
        maxlength={300}
        value={modal.newItem}
        oninput={(e: Event) => (modal.newItem = (e.target as HTMLInputElement).value)}
        onkeydown={(e: KeyboardEvent) => {
          if (e.key === 'Enter') add();
        }}
      />
      <Button className="Button" onclick={add}>
        {t('card.add')}
      </Button>
    </div>
  );
}

/** The linked discussion and its last replies, or (for editors) the way to link or start one. */
export function discussion(modal: CardModal, card: Detail): Mithril.Children {
  const d = card.discussion;
  const edit = card.abilities.edit;
  if (d) return linkedThread(modal, d, edit);
  if (card.discussionId) return null;
  if (!edit) return null;
  return (
    <section className="CorkboardCardModal-section">
      <div className="CorkboardCardModal-sectionHead">
        <h3>{t('card.discussion')}</h3>
      </div>
      {modal.linking ? discussionPicker(modal) : discussionButtons(modal)}
    </section>
  );
}

function linkedThread(modal: CardModal, d: LinkedDiscussion, edit: boolean): Mithril.Children {
  return (
    <section className="CorkboardCardModal-section CorkboardThread">
      <div className="CorkboardThread-head">
        <Icon name="fas fa-link" />
        <Link href={app.route.discussion({ id: () => String(d.id), slug: () => d.slug } as any)}>{d.title}</Link>
        <span className="CorkboardThread-count">{t('card.replies', { count: d.replyCount })}</span>
        {edit ? (
          <Button className="Button Button--link" onclick={() => modal.save({ discussionId: null })}>
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

function discussionPicker(modal: CardModal): Mithril.Children {
  return (
    <div className="CorkboardPicker">
      <input
        className="FormControl"
        type="search"
        placeholder={tx('card.find_discussion')}
        aria-label={tx('card.find_discussion')}
        oncreate={(v: Mithril.VnodeDOM) => (v.dom as HTMLInputElement).focus()}
        oninput={(e: Event) => modal.findDiscussions((e.target as HTMLInputElement).value)}
      />
      <ul className="CorkboardPicker-list">
        {modal.found.map((f) => (
          <li>
            <button onclick={() => ((modal.linking = false), modal.save({ discussionId: Number(f.id) }))}>{f.title}</button>
          </li>
        ))}
      </ul>
    </div>
  );
}

function discussionButtons(modal: CardModal): Mithril.Children {
  return (
    <div className="CorkboardCardModal-buttons">
      <Button className="Button" icon="fas fa-link" onclick={() => (modal.linking = true)}>
        {t('card.link_discussion')}
      </Button>
      {app.forum.attribute('canStartDiscussion') ? (
        <Button className="Button" icon="fas fa-comments" loading={modal.busy} onclick={() => modal.startDiscussion()}>
          {t('card.start_discussion')}
        </Button>
      ) : null}
    </div>
  );
}
