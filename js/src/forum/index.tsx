import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import Notification from 'flarum/forum/components/Notification';
import DiscussionControls from 'flarum/forum/utils/DiscussionControls';
import type Mithril from 'mithril';
import { CorkboardBoard, CorkboardCard } from './models';
import { registerFof, registerPageBuilder } from './widgets/hosts';
import { boardUrl, t, ui } from './util';

export { default as CorkboardWidget } from './widgets/Widget';

/*
 * forum.js wires Corkboard in and carries the widgets (they show on ordinary pages). The boards
 * list, a board, its settings, My cards and every dialog are one chunk (./components), fetched
 * the first time one is opened.
 */

const NOTIFICATIONS: Record<string, string> = {
  corkboardAssigned: 'fas fa-user-tag',
  corkboardDueSoon: 'far fa-clock',
  corkboardOverdue: 'fas fa-triangle-exclamation',
  corkboardMentioned: 'fas fa-at',
  corkboardCommented: 'far fa-comment',
  corkboardDone: 'fas fa-check',
  corkboardTeamAdded: 'fas fa-users',
  corkboardCompleted: 'fas fa-flag-checkered',
};

function notificationComponent(type: string) {
  return class extends Notification {
    icon() {
      return NOTIFICATIONS[type];
    }
    href() {
      const subject: any = (this.attrs.notification as any).subject();
      if (!subject) return app.route('corkboard.index');
      return subject instanceof CorkboardCard
        ? boardUrl({ id: subject.boardId(), slug: subject.boardSlug() }, Number(subject.id()))
        : boardUrl({ id: Number(subject.id()), slug: subject.slug() });
    }
    content() {
      const n: any = this.attrs.notification;
      const subject: any = n.subject();
      return t(`notification.${type}`, { user: n.fromUser(), card: subject?.title?.() || '', board: subject?.boardTitle ? subject.boardTitle() : subject?.title?.() || '' });
    }
    excerpt() {
      const subject: any = (this.attrs.notification as any).subject();
      return subject instanceof CorkboardCard ? subject.boardTitle() : '';
    }
  };
}

/** "On Spring Meetup 2027 · Booked" chips for a discussion, linking to the cards. */
function chips(discussion: any): Mithril.Children {
  const cards: any[] = discussion.attribute?.('corkboardCards') || [];
  if (!cards.length) return null;
  return (
    <span className="CorkboardDiscussionChips">
      {cards.slice(0, 3).map((c) => (
        <Link className="CorkboardDiscussionChip" href={boardUrl({ id: c.boardId, slug: c.slug }, c.cardId)} style={c.color ? { '--board-color': c.color } : {}}>
          <Icon name="fas fa-table-columns" />
          {t('discussion.chip', { board: c.board, column: c.column })}
        </Link>
      ))}
    </span>
  );
}

const lazy = (name: 'BoardsPage' | 'BoardPage' | 'SettingsPage' | 'MyCardsPage') => () =>
  ui().then(async (chunk) => {
    await chunk.loadCore();
    return { default: chunk[name] as any };
  });

app.initializers.add('ernestdefoe-corkboard', () => {
  app.store.models['corkboard-cards'] = CorkboardCard;
  app.store.models['corkboard-boards'] = CorkboardBoard;

  try {
    registerPageBuilder();
  } catch (e) {
    console.warn('[corkboard] Page Builder registration failed', e);
  }

  // /corkboard/mine before /corkboard/:id: routes match in order.
  app.routes['corkboard.index'] = { path: '/corkboard', component: lazy('BoardsPage') };
  app.routes['corkboard.mine'] = { path: '/corkboard/mine', component: lazy('MyCardsPage') };
  app.routes['corkboard.settings'] = { path: '/corkboard/:id/settings', component: lazy('SettingsPage') };
  app.routes['corkboard.board'] = { path: '/corkboard/:id', component: lazy('BoardPage') };

  Object.keys(NOTIFICATIONS).forEach((type) => (app.notificationComponents[type] = notificationComponent(type)));

  // String form: these live in lazy chunks; extending an imported copy patches one core never renders.
  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    Object.entries(NOTIFICATIONS).forEach(([name, icon]) => items.add(name, { name, icon, label: t(`notification.${name}_preference`) }));
  });

  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<Mithril.Children>) {
    if (app.forum.attribute('corkboardNav') === false || !app.forum.attribute('corkboardCanView')) return;
    items.add('corkboard', LinkButton.component({ href: app.route('corkboard.index'), icon: 'fas fa-table-columns' }, t('nav')), 72);
    if (app.session.user) items.add('corkboardMine', LinkButton.component({ href: app.route('corkboard.mine'), icon: 'fas fa-id-card' }, t('mine.title')), 71);
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

  extend(DiscussionControls, 'userControls', function (items: ItemList<Mithril.Children>, discussion: any) {
    if (!app.session.user || !app.forum.attribute('corkboardCanView')) return;
    items.add(
      'corkboard',
      Button.component({ icon: 'fas fa-table-columns', onclick: () => ui().then(({ AddToBoardModal }) => app.modal.show(AddToBoardModal, { discussion })) }, t('discussion.add')),
      -10
    );
  });
});

// After every initializer, so fof/forum-widgets-core has set itself up.
override(app, 'mount', function (original: any, ...args: any[]) {
  try {
    registerFof();
  } catch (e) {
    console.warn('[corkboard] FoF widget registration failed', e);
  }
  return original(...args);
});
