import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { call, t, tx } from '../util';
import type { BoardState } from './BoardState';

export interface MoveCardModalAttrs extends IFormModalAttrs {
  /** Not `state`: core gives every modal its own `state` (the modal manager). */
  board: BoardState;
  cardId: number;
}

/**
 * Move… : the column and the place, with big targets. The way to move a card without dragging
 * it (a phone, a switch, anyone who'd rather tap).
 */
export default class MoveCardModal extends FormModal<MoveCardModalAttrs> {
  columnId = 0;
  place: 'top' | 'bottom' = 'top';

  oninit(vnode: Mithril.Vnode<MoveCardModalAttrs, this>) {
    super.oninit(vnode);
    const card = this.attrs.board.data!.cards.find((c) => c.id === this.attrs.cardId);
    this.columnId = card?.columnId || this.attrs.board.data!.columns[0].id;
  }

  className() {
    return 'CorkboardMoveModal Modal--small';
  }

  title() {
    return tx('move.title');
  }

  content() {
    const s = this.attrs.board;
    const card = s.data!.cards.find((c) => c.id === this.attrs.cardId);
    return (
      <div className="Modal-body">
        <div className="Form">
          <fieldset className="Form-group">
            <legend>{t('move.column')}</legend>
            <div className="CorkboardMoveModal-columns">
              {s.data!.columns.map((c) => (
                <label className={classList('CorkboardMoveModal-option', this.columnId === c.id && 'is-on')}>
                  <input type="radio" name="ck-move-column" checked={this.columnId === c.id} onchange={() => (this.columnId = c.id)} />
                  <span className="CorkboardMoveModal-name">{c.title}</span>
                  <span className="CorkboardMuted">{c.id === card?.columnId ? tx('move.here') : s.columnCards(c.id).length}</span>
                </label>
              ))}
            </div>
          </fieldset>
          <div className="Form-group">
            <label for="ck-move-place">{t('move.place')}</label>
            <Select id="ck-move-place" value={this.place} options={{ top: tx('move.top'), bottom: tx('move.bottom') }} onchange={(value: 'top' | 'bottom') => (this.place = value)} />
          </div>
          <div className="Form-group">
            <Button className="Button Button--primary Button--block" type="submit" loading={this.loading}>
              {t('move.go', { column: s.data!.columns.find((c) => c.id === this.columnId)?.title || '' })}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    const s = this.attrs.board;
    const list = s.columnCards(this.columnId).filter((c) => c.id !== this.attrs.cardId);
    const body: Record<string, any> = { columnId: this.columnId, afterId: this.place === 'top' ? null : list.length ? list[list.length - 1].id : null };
    this.loading = true;
    call('PATCH', `/cards/${this.attrs.cardId}`, body)
      .then((answer) => {
        s.card(answer);
        this.hide();
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
