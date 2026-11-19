import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import { t, tx, write } from '../util';
import type { BoardState, Column } from './BoardState';

export interface ColumnModalAttrs extends IFormModalAttrs {
  /** Not `state`: core gives every modal its own `state` (the modal manager). */
  board: BoardState;
  column?: Column;
}

/** Add a column, or change one: its name, whether it counts as done, a card limit, auto-archive, move, delete. */
export default class ColumnModal extends FormModal<ColumnModalAttrs> {
  title_ = '';
  isDone = false;
  wipLimit = '';
  archiveDays = '';

  oninit(vnode: Mithril.Vnode<ColumnModalAttrs, this>) {
    super.oninit(vnode);
    const c = this.attrs.column;
    this.title_ = c?.title || '';
    this.isDone = !!c?.isDone;
    this.wipLimit = c?.wipLimit ? String(c.wipLimit) : '';
    this.archiveDays = c?.autoArchiveDays ? String(c.autoArchiveDays) : '';
  }

  className() {
    return 'CorkboardColumnModal Modal--small';
  }

  title() {
    return this.attrs.column ? tx('column.edit_title') : tx('board.add_column');
  }

  content() {
    const c = this.attrs.column;
    const columns = this.attrs.board.data!.columns;
    const i = c ? columns.findIndex((x) => x.id === c.id) : -1;
    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            <label for="ck-column-title">{t('column.name')}</label>
            <input
              id="ck-column-title"
              className="FormControl"
              maxlength={80}
              value={this.title_}
              oninput={(e: Event) => (this.title_ = (e.target as HTMLInputElement).value)}
            />
          </div>
          <div className="Form-group">
            <label className="checkbox">
              <input type="checkbox" checked={this.isDone} onchange={() => (this.isDone = !this.isDone)} />
              {t('column.is_done')}
            </label>
            <p className="helpText">{t('column.is_done_help')}</p>
          </div>
          {this.wipField()}
          {this.isDone ? this.archiveField() : null}
          {c ? this.orderButtons(columns, i) : null}
          <div className="Form-group CorkboardModal-actions">
            <Button className="Button Button--primary" type="submit" loading={this.loading}>
              {t('save')}
            </Button>
            {c ? (
              <Button className="Button Button--danger" onclick={() => this.remove()}>
                {t('column.delete')}
              </Button>
            ) : null}
          </div>
        </div>
      </div>
    );
  }

  /** At most this many cards before the column shows it is over. */
  wipField(): Mithril.Children {
    return (
      <div className="Form-group">
        <label for="ck-column-wip">{t('column.wip')}</label>
        <input
          id="ck-column-wip"
          className="FormControl"
          type="number"
          min="1"
          max="999"
          value={this.wipLimit}
          oninput={(e: Event) => (this.wipLimit = (e.target as HTMLInputElement).value)}
        />
        <p className="helpText">{t('column.wip_explain')}</p>
      </div>
    );
  }

  archiveField(): Mithril.Children {
    return (
      <div className="Form-group">
        <label for="ck-column-archive">{t('column.auto_archive')}</label>
        <input
          id="ck-column-archive"
          className="FormControl"
          type="number"
          min="1"
          max="365"
          value={this.archiveDays}
          oninput={(e: Event) => (this.archiveDays = (e.target as HTMLInputElement).value)}
        />
      </div>
    );
  }

  /** Move an existing column one place left or right. */
  orderButtons(columns: Column[], i: number): Mithril.Children {
    return (
      <div className="Form-group CorkboardColumnModal-order">
        <Button className="Button" icon="fas fa-arrow-left" disabled={i <= 0} onclick={() => this.reorder(i - 2 >= 0 ? columns[i - 2].id : null)}>
          {t('column.move_left')}
        </Button>
        <Button className="Button" icon="fas fa-arrow-right" disabled={i >= columns.length - 1} onclick={() => this.reorder(columns[i + 1].id)}>
          {t('column.move_right')}
        </Button>
      </div>
    );
  }

  reorder(afterId: number | null): void {
    write('PATCH', 'columns', this.attrs.column!.id, { afterId }).then((answer) => {
      this.attrs.board.replace(answer);
      this.hide();
    });
  }

  remove(): void {
    if (!confirm(tx('column.delete_confirm', { title: this.attrs.column!.title }))) return;
    write('DELETE', 'columns', this.attrs.column!.id).then(() => {
      this.attrs.board.load();
      this.hide();
    });
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;
    const body = {
      title: this.title_,
      isDone: this.isDone,
      wipLimit: this.wipLimit ? Number(this.wipLimit) : null,
      autoArchiveDays: this.isDone && this.archiveDays ? Number(this.archiveDays) : null,
    };
    const c = this.attrs.column;
    write(c ? 'PATCH' : 'POST', 'columns', c ? c.id : null, c ? body : { ...body, boardId: this.attrs.board.id })
      .then((answer) => {
        this.attrs.board.replace(answer);
        this.hide();
      })
      .catch(() => {
        /* write() already showed the error */
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
