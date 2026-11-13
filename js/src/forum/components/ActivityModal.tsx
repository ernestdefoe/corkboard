import Modal, { type IInternalModalAttrs } from 'flarum/common/components/Modal';
import { tx } from '../util';
import type { BoardState } from './BoardState';
import ActivityList from './ActivityList';

/** The board's whole history. */
export default class ActivityModal extends Modal<IInternalModalAttrs & { board: BoardState }> {
  static isDismissibleViaBackdropClick = true;

  className() {
    return 'CorkboardActivityModal';
  }

  title() {
    return tx('board.activity');
  }

  content() {
    return (
      <div className="Modal-body">
        <ActivityList state={this.attrs.board} />
      </div>
    );
  }
}
