import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import { ago, Avatar, commentList, t, tx, write } from '../../util';
import type CardModal from '../CardModal';
import type { CardComment, Detail } from './types';

/** The comments tab: the composer, the comments newest first, and the button for older ones. */
export function comments(modal: CardModal, card: Detail): Mithril.Children {
  const me = app.session.user;
  const send = () => sendComment(modal, card);
  return (
    <div className="CorkboardComments">
      {card.abilities.comment && me ? composer(modal, me, send) : null}
      {card.comments.map((c) => comment(modal, card, c))}
      {!card.comments.length ? <p className="CorkboardCardModal-none">{t('card.no_comments')}</p> : null}
      {card.commentsMore ? (
        <Button
          className="Button Button--link"
          onclick={() =>
            commentList(card.id, card.comments[card.comments.length - 1].id).then((answer) => {
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

function sendComment(modal: CardModal, card: Detail): void {
  const content = modal.comment.trim();
  if (!content) return;
  modal.busy = true;
  write('POST', 'comments', null, { cardId: card.id, content })
    .then((answer) => {
      modal.comment = '';
      card.comments = answer.comments;
      card.commentsMore = answer.more;
      Object.assign(card.users, answer.users);
      card.commentCount = answer.commentCount;
      const slim = modal.attrs.board.data?.cards.find((c) => c.id === card.id);
      if (slim) slim.commentCount = answer.commentCount;
      modal.attrs.board.seen(answer.version);
    })
    .finally(() => {
      modal.busy = false;
      m.redraw();
    });
}

function composer(modal: CardModal, me: NonNullable<typeof app.session.user>, send: () => void): Mithril.Children {
  return (
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
          value={modal.comment}
          oninput={(e: Event) => (modal.comment = (e.target as HTMLTextAreaElement).value)}
          onkeydown={(e: KeyboardEvent) => {
            if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) send();
          }}
        />
        {modal.comment.trim() ? (
          <Button className="Button Button--primary" loading={modal.busy} onclick={send}>
            {t('card.send')}
          </Button>
        ) : null}
      </div>
    </div>
  );
}

function comment(modal: CardModal, card: Detail, c: CardComment): Mithril.Children {
  const user = card.users[c.userId!];
  return (
    <div className="CorkboardComment" key={c.id}>
      <Avatar user={user} size={30} />
      <div className="CorkboardComment-body">
        <div className="CorkboardComment-head">
          <strong>{user ? user.name : tx('deleted_user')}</strong> <span className="CorkboardMuted">{ago(c.createdAt)}</span>
          {c.editedAt ? <span className="CorkboardMuted"> · {t('card.edited')}</span> : null}
          {c.canEdit && modal.editingComment !== c.id ? (
            <Button className="Button Button--link" onclick={() => ((modal.editingComment = c.id), (modal.commentDraft = c.source || ''))}>
              {t('card.edit')}
            </Button>
          ) : null}
          {c.canDelete ? (
            <Button
              className="Button Button--link"
              onclick={() => {
                if (confirm(tx('card.delete_comment_confirm'))) write('DELETE', 'comments', c.id).then(() => modal.load());
              }}
            >
              {t('card.delete')}
            </Button>
          ) : null}
        </div>
        {modal.editingComment === c.id ? editor(modal, card, c) : <div className="CorkboardComment-text Post-body">{m.trust(c.html)}</div>}
      </div>
    </div>
  );
}

function editor(modal: CardModal, card: Detail, c: CardComment): Mithril.Children {
  return (
    <div className="CorkboardCardModal-editor">
      <textarea
        className="FormControl"
        rows={3}
        aria-label={tx('card.edit')}
        value={modal.commentDraft}
        oninput={(e: Event) => (modal.commentDraft = (e.target as HTMLTextAreaElement).value)}
      />
      <div className="CorkboardCardModal-editorActions">
        <Button
          className="Button Button--primary"
          onclick={() =>
            write('PATCH', 'comments', c.id, { content: modal.commentDraft }).then((answer) => {
              card.comments = answer.comments;
              modal.editingComment = null;
              m.redraw();
            })
          }
        >
          {t('save')}
        </Button>
        <Button className="Button Button--link" onclick={() => (modal.editingComment = null)}>
          {t('cancel')}
        </Button>
      </div>
    </div>
  );
}
