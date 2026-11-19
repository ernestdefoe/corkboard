import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import { CorkboardCard } from './models';
import { boardUrl, t } from './util';

export const NOTIFICATIONS: Record<string, string> = {
  corkboardAssigned: 'fas fa-user-tag',
  corkboardDueSoon: 'far fa-clock',
  corkboardOverdue: 'fas fa-triangle-exclamation',
  corkboardMentioned: 'fas fa-at',
  corkboardCommented: 'far fa-comment',
  corkboardDone: 'fas fa-check',
  corkboardTeamAdded: 'fas fa-users',
  corkboardCompleted: 'fas fa-flag-checkered',
};

export function notificationComponent(type: string) {
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
      return t(`notification.${type}`, {
        user: n.fromUser(),
        card: subject?.title?.() || '',
        board: subject?.boardTitle ? subject.boardTitle() : subject?.title?.() || '',
      });
    }
    excerpt() {
      const subject: any = (this.attrs.notification as any).subject();
      return subject instanceof CorkboardCard ? subject.boardTitle() : '';
    }
  };
}
