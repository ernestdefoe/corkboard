import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import DiscussionControls from 'flarum/forum/utils/DiscussionControls';
import type Mithril from 'mithril';
import { registerFof, registerPageBuilder } from './widgets/hosts';
import { NOTIFICATIONS } from './notifications';
import { boardUrl, t, ui } from './util';
import ProjectBoards from './ProjectBoards';

export { default as extend } from './extend';
export { default as CorkboardWidget } from './widgets/Widget';

/*
 * forum.js wires Corkboard in and carries the widgets (they show on ordinary pages). The boards
 * list, a board, its settings, My cards and every dialog are one chunk (./components), fetched
 * the first time one is opened.
 */

/** "On Spring Meetup 2027 · Booked" chips for a discussion, linking to the cards. */
function chips(discussion: any): Mithril.Children {
  const cards: any[] = discussion.attribute?.('corkboardCards') || [];
  if (!cards.length) return null;
  return (
    <span className="CorkboardDiscussionChips">
      {cards.slice(0, 3).map((c) => (
        <Link
          className="CorkboardDiscussionChip"
          href={boardUrl({ id: c.boardId, slug: c.slug }, c.cardId)}
          style={c.color ? { '--board-color': c.color } : {}}
        >
          <Icon name="fas fa-table-columns" />
          {t('discussion.chip', { board: c.board, column: c.column })}
        </Link>
      ))}
    </span>
  );
}

app.initializers.add('ernestdefoe-corkboard', () => {
  // A host that isn't installed, or that changed shape, just goes without Corkboard's widgets.
  try {
    registerPageBuilder();
  } catch {
    /* Page Builder is absent or changed: its blocks are optional */
  }

  // String form: these live in lazy chunks; extending an imported copy patches one core never renders.
  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    Object.entries(NOTIFICATIONS).forEach(([name, icon]) => items.add(name, { name, icon, label: t(`notification.${name}_preference`) }));
  });

  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<Mithril.Children>) {
    if (app.forum.attribute('corkboardNav') === false || !app.forum.attribute('corkboardCanView')) return;
    items.add('corkboard', LinkButton.component({ href: app.route('corkboard.index'), icon: 'fas fa-table-columns' }, t('nav')), 72);
    if (app.session.user)
      items.add('corkboardMine', LinkButton.component({ href: app.route('corkboard.mine'), icon: 'fas fa-id-card' }, t('mine.title')), 71);
  });

  // The chip under a discussion's title in lists and on the discussion itself.
  extend('flarum/forum/components/DiscussionListItem', 'infoItems', function (this: any, items: ItemList<Mithril.Children>) {
    const chip = chips(this.attrs.discussion);
    if (chip) items.add('corkboard', chip, -10);
  });
  extend('flarum/forum/components/DiscussionHero', 'items', function (this: any, items: ItemList<Mithril.Children>) {
    const chip = chips(this.attrs.discussion);
    if (chip) items.add('corkboard', chip, -5);
  });

  // ernestdefoe/projects: a project's boards on its page (its page is a lazy chunk: extended by name).
  if ('ernestdefoe-projects' in ((window as any).flarum?.extensions || {})) {
    override('ext:ernestdefoe/projects/forum/components/ProjectPage', 'management', function (this: any, original: any, project: any) {
      return [<ProjectBoards project={project} />, original(project)];
    });
  }

  extend(DiscussionControls, 'userControls', function (items: ItemList<Mithril.Children>, discussion: any) {
    if (!app.session.user || !app.forum.attribute('corkboardCanView')) return;
    items.add(
      'corkboard',
      Button.component(
        { icon: 'fas fa-table-columns', onclick: () => ui().then(({ AddToBoardModal }) => app.modal.show(AddToBoardModal, { discussion })) },
        t('discussion.add')
      ),
      -10
    );
  });
});

// Last of all initializers (lowest priority), so fof/forum-widgets-core has set itself up.
app.initializers.add(
  'ernestdefoe-corkboard-fof-widgets',
  () => {
    try {
      registerFof();
    } catch {
      /* fof/forum-widgets-core is absent or changed: its widgets are optional */
    }
  },
  -1000
);
