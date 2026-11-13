import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import { boardUrl, call, t, tx, ui } from './util';

interface Tile {
  id: number;
  slug: string;
  title: string;
  color: string;
  cardCount: number;
  doneCount: number;
  dueSoon: number;
  status: string;
}

/**
 * On a project's page (ernestdefoe/projects): its boards with their progress, for people who may
 * see them, and "Add a board" for the project's authors. Nothing at all when there's nothing to show.
 */
export default class ProjectBoards extends Component<{ project: any }> {
  boards: Tile[] | null = null;

  oninit(vnode: any) {
    super.oninit(vnode);
    call('GET', '/boards', undefined, { tab: 'all', project: this.attrs.project.id }, true)
      .then((answer) => (this.boards = answer.boards))
      .catch(() => (this.boards = []))
      .finally(() => m.redraw());
  }

  view() {
    const p = this.attrs.project;
    const me = app.session.user ? Number(app.session.user.id()) : 0;
    const author = !!me && (Number(p.author?.id) === me || (p.coAuthors || []).some((a: any) => Number(a.id) === me));
    const canAdd = author && app.forum.attribute('corkboardCanCreate');
    if (!this.boards || (!this.boards.length && !canAdd)) return null;
    return (
      <section className="CorkboardProjectBoards">
        <h2 className="CorkboardProjectBoards-title">
          <Icon name="fas fa-table-columns" /> {t('project.title')}
        </h2>
        {this.boards.map((b) => {
          const pct = b.cardCount ? Math.round((b.doneCount / b.cardCount) * 100) : 0;
          return (
            <Link className="CorkboardProjectBoards-board" href={boardUrl(b)} style={{ '--board-color': b.color }}>
              <span className="CorkboardProjectBoards-name">{b.title}</span>
              <span
                className="CorkboardProgress"
                role="progressbar"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow={pct}
                aria-label={tx('list.progress', { done: b.doneCount, total: b.cardCount })}
              >
                <span className="CorkboardProgress-bar" style={{ width: `${pct}%` }} />
              </span>
              <span className="CorkboardProjectBoards-meta">
                {t('list.progress', { done: b.doneCount, total: b.cardCount })}
                {b.dueSoon ? <> · {t('list.due_soon', { count: b.dueSoon })}</> : null}
              </span>
            </Link>
          );
        })}
        {canAdd ? (
          <Button
            className="Button"
            icon="fas fa-plus"
            onclick={() => ui().then(({ CreateBoardModal }) => app.modal.show(CreateBoardModal, { projectId: Number(p.id), template: 'simple' }))}
          >
            {t('project.add')}
          </Button>
        ) : null}
      </section>
    );
  }
}
