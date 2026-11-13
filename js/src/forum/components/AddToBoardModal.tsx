import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import { boardUrl, call, t, tx } from '../util';

export interface AddToBoardModalAttrs extends IFormModalAttrs {
  discussion: any;
}

/** "Add to a board" from a discussion: a card on one of the viewer's boards, linked back to it. */
export default class AddToBoardModal extends FormModal<AddToBoardModalAttrs> {
  boards: Array<{ id: number; slug: string; title: string; role: string | null }> | null = null;
  boardId = 0;
  columns: Array<{ id: number; title: string }> = [];
  columnId = 0;
  name = '';

  oninit(vnode: Mithril.Vnode<AddToBoardModalAttrs, this>) {
    super.oninit(vnode);
    this.name = this.attrs.discussion.title();
    call('GET', '/boards', undefined, { tab: 'mine' }, true).then((answer) => {
      this.boards = answer.boards.filter((b: any) => b.role === 'editor' || b.role === 'manager');
      if (this.boards!.length) this.pick(this.boards![0].id);
      m.redraw();
    });
  }

  className() {
    return 'CorkboardAddModal Modal--small';
  }

  title() {
    return tx('add.title');
  }

  pick(id: number): void {
    this.boardId = id;
    this.columns = [];
    call('GET', `/boards/${id}`, undefined, undefined, true).then((answer) => {
      this.columns = answer.columns;
      this.columnId = answer.columns[0]?.id || 0;
      m.redraw();
    });
  }

  content() {
    if (!this.boards) return <div className="Modal-body"><LoadingIndicator /></div>;
    if (!this.boards.length) return <div className="Modal-body"><p>{t('add.no_boards')}</p></div>;
    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            <label for="ck-add-board">{t('add.board')}</label>
            <Select id="ck-add-board" value={String(this.boardId)} options={Object.fromEntries(this.boards.map((b) => [String(b.id), b.title]))} onchange={(value: string) => this.pick(Number(value))} />
          </div>
          <div className="Form-group">
            <label for="ck-add-column">{t('add.column')}</label>
            <Select id="ck-add-column" value={String(this.columnId)} options={Object.fromEntries(this.columns.map((c) => [String(c.id), c.title]))} onchange={(value: string) => (this.columnId = Number(value))} />
          </div>
          <div className="Form-group">
            <label for="ck-add-title">{t('add.card_title')}</label>
            <input id="ck-add-title" className="FormControl" maxlength={160} value={this.name} oninput={(e: Event) => (this.name = (e.target as HTMLInputElement).value)} />
          </div>
          <div className="Form-group">
            <Button className="Button Button--primary Button--block" type="submit" loading={this.loading} disabled={!this.columnId || !this.name.trim()}>
              {t('add.submit')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;
    const board = this.boards!.find((b) => b.id === this.boardId)!;
    call('POST', `/boards/${this.boardId}/cards`, { columnId: this.columnId, title: this.name, discussionId: Number(this.attrs.discussion.id()) })
      .then((answer) => {
        this.hide();
        app.alerts.show({ type: 'success' }, tx('add.done', { board: board.title }));
        const chips = this.attrs.discussion.attribute('corkboardCards') || [];
        this.attrs.discussion.pushAttributes({ corkboardCards: [...chips, { cardId: answer.card.id, boardId: board.id, board: board.title, slug: board.slug, column: this.columns.find((c) => c.id === this.columnId)?.title }] });
        m.route.set(boardUrl(board, answer.card.id));
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
