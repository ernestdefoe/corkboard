import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import stringToColor from 'flarum/common/utils/stringToColor';
import type Mithril from 'mithril';

export const EXT = 'ernestdefoe-corkboard';

export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`${EXT}.forum.${key}`, params);
export const tx = (key: string, params: Record<string, any> = {}) => extractText(t(key, params));

/** What a failed request does: tells the person why (unless it's a quiet read), and rejects. */
const onError = (quiet: boolean) =>
  quiet
    ? () => {}
    : (error: any) => {
        app.alerts.show({ type: 'error' }, errorText(error));
        throw error;
      };

/** Corkboard's actions that aren't a resource's create, read, update or delete, under /api/corkboard. */
export function call<T = any>(method: string, path: string, body?: any, params?: Record<string, any>, quiet = false): Promise<T> {
  return app.request<T>({
    method,
    url: `${app.forum.attribute('apiUrl')}/corkboard${path}`,
    body,
    params,
    errorHandler: onError(quiet),
  } as any);
}

/** Corkboard's JSON:API resources: corkboard-boards, -columns, -labels, -cards, -comments, -checklist-items, -members. */
export type Resource = 'boards' | 'columns' | 'labels' | 'cards' | 'comments' | 'checklist-items' | 'members';

function resource<T>(
  method: string,
  type: Resource,
  id: number | string | null,
  body?: any,
  params?: Record<string, any>,
  quiet = false
): Promise<T> {
  return app.request<T>({
    method,
    url: `${app.forum.attribute('apiUrl')}/corkboard-${type}${id ? `/${id}` : ''}`,
    body,
    params,
    errorHandler: onError(quiet),
  } as any);
}

/**
 * Create (no id), update or delete one of them. Resolves with what the change answers with (its
 * `view`: the card as the board draws it, the whole board, a page of comments…), or null for a delete.
 */
export function write<T = any>(
  method: 'POST' | 'PATCH' | 'DELETE',
  type: Resource,
  id: number | null,
  attributes: Record<string, any> = {},
  quiet = false
): Promise<T> {
  const body = method === 'DELETE' ? undefined : { data: { type: `corkboard-${type}`, ...(id ? { id: String(id) } : {}), attributes } };
  return resource<any>(method, type, id, body, undefined, quiet).then((answer) => answer?.data?.attributes?.view ?? null);
}

/** A board or a card as the page draws it (its `view`). Quiet: the caller shows what went wrong. */
export function read<T = any>(type: 'boards' | 'cards', id: number): Promise<T> {
  return resource<any>('GET', type, id, undefined, undefined, true).then((answer) => answer.data.attributes.view);
}

/** One item of a list, its id a number again. */
const item = (d: any) => ({ id: Number(d.id), ...d.attributes });

/** A tile's people come with it; the pages keep them as ids and a users map, as the board does. */
function tiles(list: any[], users: Record<string, Person>): any[] {
  return list.map((tile) => {
    tile.people.forEach((p: Person) => (users[p.id] = p));
    return { ...tile, people: tile.people.map((p: Person) => p.id) };
  });
}

/** A page of the boards list: a tab, a search, a project's boards; the pinned row on the first page. */
export function boardList(
  filter: { tab?: string; q?: string; project?: number },
  page = 0
): Promise<{ boards: any[]; pinned: any[]; more: boolean; users: Record<string, Person> }> {
  const set = Object.fromEntries(Object.entries(filter).filter(([, value]) => value !== undefined && value !== ''));
  return resource<any>('GET', 'boards', null, undefined, { filter: set, page: { offset: page * 30 } }, true).then((doc) => {
    const users: Record<string, Person> = {};
    return { boards: tiles(doc.data.map(item), users), pinned: tiles(doc.meta?.pinned || [], users), more: !!doc.links?.next, users };
  });
}

/** A card in a list: as the board draws it, where it is, and its people themselves. */
export interface ListedCard extends SlimCard {
  boardId: number;
  boardTitle: string;
  boardSlug: string;
  boardColor: string;
  columnTitle: string | null;
  archivedAt: string | null;
  people: Person[];
}

/**
 * A page of corkboard-cards: { mine: 1, owned?, done? }, { dueWithin: days, board? },
 * { column, after? } or { board, archived: 1 }. The people come with each card; they're also
 * gathered in a users map, as the board keeps them.
 */
export function cardList(
  filter: Record<string, string | number | undefined>,
  limit = 50
): Promise<{ cards: ListedCard[]; total: number; more: boolean; users: Record<string, Person> }> {
  const set = Object.fromEntries(Object.entries(filter).filter(([, value]) => value !== undefined && value !== ''));
  return resource<any>('GET', 'cards', null, undefined, { filter: set, page: { limit } }, true).then((doc) => {
    const users: Record<string, Person> = {};
    const cards: ListedCard[] = doc.data.map((d: any) => {
      const card = item(d);
      (card.people || []).forEach((p: Person) => (users[p.id] = p));
      return card;
    });
    return { cards, total: doc.meta?.page?.total ?? cards.length, more: !!doc.links?.next, users };
  });
}

/**
 * People (and, for a team, groups) whose name starts with q: people through core's own users
 * endpoint (which needs the searchUsers permission, as finding people anywhere does), groups from
 * the forum's own list. Guests and hidden groups are never offered.
 */
export function findPeople(q: string, withGroups = false): Promise<{ users: Person[]; groups: Group[] }> {
  const groups: Group[] = [];
  if (withGroups) {
    const start = q.toLowerCase();
    app.store
      .all<any>('groups')
      .filter(
        (g) => g.id() !== '2' && !g.isHidden() && [g.nameSingular(), g.namePlural()].some((n: string) => (n || '').toLowerCase().startsWith(start))
      )
      .slice(0, 5)
      .forEach((g) => groups.push({ id: Number(g.id()), name: g.namePlural(), color: g.color() || null, icon: g.icon() || null, count: null }));
  }
  return app
    .request<any>({
      method: 'GET',
      url: `${app.forum.attribute('apiUrl')}/users`,
      params: { filter: { q }, page: { limit: 8 }, sort: 'username' },
      errorHandler: onError(true),
    } as any)
    .then((doc) => ({
      users: doc.data.map((d: any) => ({
        id: Number(d.id),
        name: d.attributes.displayName,
        username: d.attributes.username,
        slug: d.attributes.slug,
        avatarUrl: d.attributes.avatarUrl ?? null,
      })),
      groups,
    }));
}

/** A page of a board's (or one card's) history, newest first, with today's names for its ids. */
export function activityPage(of: { board?: number; card?: number }, before?: number): Promise<any> {
  const filter = { ...of, ...(before ? { before } : {}) };
  return app
    .request<any>({
      method: 'GET',
      url: `${app.forum.attribute('apiUrl')}/corkboard-activity`,
      params: { filter },
      errorHandler: onError(true),
    } as any)
    .then((doc) => ({ items: doc.data.map(item), more: !!doc.links?.next, ...doc.meta.names }));
}

export interface Template {
  key: string;
  title: string;
  columns: string[];
  color?: string;
}

/** The built-in templates (a forum attribute) and the forum's template boards (a boards tab). */
export function templateList(): Promise<{ builtIn: Template[]; custom: Template[] }> {
  const builtIn: Template[] = app.forum.attribute('corkboardTemplates') || [];
  return boardList({ tab: 'templates' }).then(({ boards }) => ({
    builtIn,
    custom: boards.map((b) => ({ key: String(b.id), title: b.title, color: b.color, columns: b.columns || [] })),
  }));
}

/** A card's comments older than one already shown, as the card draws them. */
export function commentList(card: number, before: number): Promise<{ comments: any[]; more: boolean; users: Record<string, Person> }> {
  return resource<any>('GET', 'comments', null, undefined, { filter: { card, before } }).then((doc) => {
    const users: Record<string, Person> = {};
    const comments = doc.data.map((d: any) => {
      const { user, cardId, ...comment } = item(d);
      if (user) users[user.id] = user;
      return comment;
    });
    return { comments, more: !!doc.links?.next, users };
  });
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
  /** null for a hidden group, to anyone but moderators */
  name: string | null;
  color: string | null;
  icon: string | null;
  count: number | null;
  hidden?: boolean;
}

/** A team group's name; a hidden one is only named to moderators. */
export const groupName = (group: Group | null | undefined): string => (group ? (group.name ?? tx('hidden_group')) : '');

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
export function due(card: {
  dueAt: string | null;
  dueAllDay: boolean;
  completedAt?: string | null;
}): { text: string; state: 'done' | 'late' | 'soon' | 'later' } | null {
  if (!card.dueAt) return null;
  const at = new Date(card.dueAt);
  const now = Date.now();
  const days = (at.getTime() - now) / 86400000;
  const sameYear = at.getFullYear() === new Date().getFullYear();
  const opts: Intl.DateTimeFormatOptions = {
    ...MONTH,
    ...(sameYear ? {} : { year: 'numeric' }),
    ...(days >= 0 && days < 7 ? { weekday: 'short' } : {}),
  };
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
