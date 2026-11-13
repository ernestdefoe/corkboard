import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import stringToColor from 'flarum/common/utils/stringToColor';
import type Mithril from 'mithril';

export const EXT = 'ernestdefoe-corkboard';

export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`${EXT}.forum.${key}`, params);
export const tx = (key: string, params: Record<string, any> = {}) => extractText(t(key, params));

/** Corkboard's own JSON API, under /api/corkboard. */
export function call<T = any>(method: string, path: string, body?: any, params?: Record<string, any>, quiet = false): Promise<T> {
  return app.request<T>({
    method,
    url: `${app.forum.attribute('apiUrl')}/corkboard${path}`,
    body,
    params,
    errorHandler: quiet
      ? () => {}
      : (error: any) => {
          app.alerts.show({ type: 'error' }, errorText(error));
          throw error;
        },
  } as any);
}

/** The sentence for an error answer: our own refusals carry it, the rest are core's. */
export function errorText(error: any): string {
  const detail = error?.response?.errors?.[0]?.detail;
  if (detail && error?.status === 422) return String(detail);
  if (error?.status === 0) return extractText(app.translator.trans('core.lib.error.network_message'));
  if (error?.status === 403) return extractText(app.translator.trans('core.lib.error.permission_denied_message'));
  if (error?.status === 404) return tx('error.gone');
  return extractText(app.translator.trans('core.lib.error.generic_message'));
}

export interface Person {
  id: number;
  name: string;
  username: string;
  slug: string;
  avatarUrl: string | null;
}

export interface Group {
  id: number;
  name: string;
  color: string | null;
  icon: string | null;
  count: number | null;
}

export interface SlimCard {
  id: number;
  columnId: number;
  position: number;
  title: string;
  priority: Priority;
  dueAt: string | null;
  dueAllDay: boolean;
  completedAt: string | null;
  ownerId: number | null;
  discussionId: number | null;
  coverColor: string | null;
  commentCount: number;
  checklistTotal: number;
  checklistDone: number;
  labelIds: number[];
  assigneeIds: number[];
}

export type Priority = 'none' | 'low' | 'normal' | 'high' | 'urgent';
export const PRIORITIES: Priority[] = ['none', 'low', 'normal', 'high', 'urgent'];
export type Role = 'viewer' | 'commenter' | 'editor' | 'manager';
export const ROLES: Role[] = ['viewer', 'commenter', 'editor', 'manager'];
export type Visibility = 'public' | 'members' | 'team' | 'private';
export const VISIBILITIES: Visibility[] = ['public', 'members', 'team', 'private'];

export const VISIBILITY_ICON: Record<Visibility, string> = {
  public: 'fas fa-globe',
  members: 'fas fa-user-check',
  team: 'fas fa-users',
  private: 'fas fa-lock',
};

/** A person's picture, or their initials on their colour (core's own colour for a name). */
export const Avatar = {
  view(vnode: Mithril.Vnode<{ user: Person | null | undefined; size?: number; ring?: boolean }>) {
    const { user, size = 24, ring } = vnode.attrs;
    const style = { width: `${size}px`, height: `${size}px`, fontSize: `${Math.round(size * 0.4)}px` };
    const cls = 'CorkboardAvatar' + (ring ? ' CorkboardAvatar--ring' : '');
    if (!user) return <span className={cls + ' CorkboardAvatar--gone'} style={style} aria-hidden="true" />;
    if (user.avatarUrl) return <img className={cls} style={style} src={user.avatarUrl} alt="" loading="lazy" title={user.name} />;
    const initials = user.name
      .split(/\s+/)
      .map((w) => w[0] || '')
      .join('')
      .slice(0, 2)
      .toUpperCase();
    return (
      <span className={cls} style={{ ...style, background: `#${stringToColor(user.name)}` }} title={user.name} aria-hidden="true">
        {initials}
      </span>
    );
  },
};

const MONTH = { month: 'short', day: 'numeric' } as const;

/** "Fri, Nov 14", "Dec 1", "Nov 14, 5:00 PM" and how urgent it is. */
export function due(card: { dueAt: string | null; dueAllDay: boolean; completedAt?: string | null }): { text: string; state: 'done' | 'late' | 'soon' | 'later' } | null {
  if (!card.dueAt) return null;
  const at = new Date(card.dueAt);
  const now = Date.now();
  const days = (at.getTime() - now) / 86400000;
  const sameYear = at.getFullYear() === new Date().getFullYear();
  const opts: Intl.DateTimeFormatOptions = { ...MONTH, ...(sameYear ? {} : { year: 'numeric' }), ...(days >= 0 && days < 7 ? { weekday: 'short' } : {}) };
  if (!card.dueAllDay) Object.assign(opts, { hour: 'numeric', minute: '2-digit' });
  const text = at.toLocaleString(app.translator.getLocale() || undefined, card.dueAllDay ? { ...opts, timeZone: 'UTC' } : opts);
  const state = card.completedAt ? 'done' : at.getTime() < now ? 'late' : days <= 2 ? 'soon' : 'later';
  return { text, state };
}

/** "12 min ago", "3 d ago", then a date. */
export function ago(iso: string | null | undefined): string {
  if (!iso) return '';
  const s = Math.max(1, Math.round((Date.now() - new Date(iso).getTime()) / 1000));
  if (s < 60) return tx('time.now');
  if (s < 3600) return tx('time.minutes', { count: Math.floor(s / 60) });
  if (s < 86400) return tx('time.hours', { count: Math.floor(s / 3600) });
  if (s < 86400 * 7) return tx('time.days', { count: Math.floor(s / 86400) });
  return new Date(iso).toLocaleDateString(app.translator.getLocale() || undefined, MONTH);
}

/** Text that sits on a colour: dark on light labels, white on the rest. */
export function onColor(hex: string | null | undefined): string {
  const h = (hex || '#888888').replace('#', '');
  const [r, g, b] = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16) / 255).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
  const lum = 0.2126 * r + 0.7152 * g + 0.0722 * b;
  return lum > 0.4 ? '#1f2733' : '#ffffff';
}

export function boardUrl(board: { id: number; slug?: string }, card?: number): string {
  const id = board.slug ? `${board.id}-${board.slug}` : String(board.id);
  return app.route('corkboard.board', { id }) + (card ? `?card=${card}` : '');
}

/** The lazy chunk: every page and dialog, fetched the first time one is needed. */
export const ui = () => import('./components');
