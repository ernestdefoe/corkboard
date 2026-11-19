import Extend from 'flarum/common/extenders';
import { CorkboardBoard, CorkboardCard } from './models';
import { NOTIFICATIONS, notificationComponent } from './notifications';
import { ui } from './util';

/*
 * The boards list, a board, its settings and My cards are one lazy chunk (./components), fetched
 * the first time one is opened, with core's lazy pieces it needs loaded alongside.
 */
const page = (name: 'BoardsPage' | 'BoardPage' | 'SettingsPage' | 'MyCardsPage') => () =>
  ui().then(async (chunk) => {
    await chunk.loadCore();
    return { default: chunk[name] as any };
  });

const notifications = new Extend.Notification();
Object.keys(NOTIFICATIONS).forEach((type) => notifications.add(type, notificationComponent(type)));

export default [
  new Extend.Store().add('corkboard-cards', CorkboardCard).add('corkboard-boards', CorkboardBoard),

  // /corkboard/mine before /corkboard/:id: routes match in order.
  new Extend.Routes()
    .add('corkboard.index', '/corkboard', page('BoardsPage'))
    .add('corkboard.mine', '/corkboard/mine', page('MyCardsPage'))
    .add('corkboard.settings', '/corkboard/:id/settings', page('SettingsPage'))
    .add('corkboard.board', '/corkboard/:id', page('BoardPage')),

  notifications,
];
