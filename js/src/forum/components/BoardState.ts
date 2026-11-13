import app from 'flarum/forum/app';
import { call, type Group, type Person, type Role, type SlimCard, type Visibility } from '../util';

export interface Column {
  id: number;
  title: string;
  color: string | null;
  wipLimit: number | null;
  isDone: boolean;
  autoArchiveDays: number | null;
  more: number;
}

export interface Label {
  id: number;
  name: string;
  color: string;
}

export interface Member {
  id: number;
  userId: number | null;
  groupId: number | null;
  role: Role;
}

export interface BoardInfo {
  id: number;
  slug: string;
  title: string;
  color: string;
  visibility: Visibility;
  status: 'active' | 'completed' | 'archived';
  isTemplate: boolean;
  ownerId: number | null;
  cardCount: number;
  doneCount: number;
  version: number;
  completedAt: string | null;
  archivedAt: string | null;
  lastActivityAt: string | null;
  description: string | null;
  tagId: number | null;
  tag: { id: number; name: string; slug: string; color: string | null } | null;
  projectId: number | null;
  project: { id: number; title: string; slug: string } | null;
}

export interface Abilities {
  comment: boolean;
  edit: boolean;
  manage: boolean;
  reopen: boolean;
  template: boolean;
  public: boolean;
}

export interface BoardPayload {
  board: BoardInfo;
  role: Role | null;
  abilities: Abilities;
  columns: Column[];
  labels: Label[];
  members: Member[];
  cards: SlimCard[];
  users: Record<string, Person>;
  groups: Record<string, Group>;
  pinned?: boolean;
  projects?: Array<{ id: number; title: string }>;
  push?: boolean;
}

const POLL = 20000;
const IDLE_POLL = 60000;
const IDLE_AFTER = 5 * 60000;

/**
 * One open board, kept in a module-level store so a page remount (opening a card changes the
 * route) doesn't fetch it again.
 *
 * Live: every 20 seconds while the tab is visible it asks "still at version N?" (one cheap query),
 * slowing to a minute after five quiet minutes; with flarum/realtime the team's browsers also
 * hear about a change the moment it happens. A change somebody else made refetches the board;
 * never in the middle of a drag (it waits until the card is dropped).
 */
export class BoardState {
  data: BoardPayload | null = null;
  error: number | null = null;
  loading = false;
  /** Someone else changed the board while a card was in the air. */
  stale = false;
  dragging = false;
  /** user id => [card id, until] for "Ana is moving this" */
  moving: Record<number, { card: number | null; name: string; until: number }> = {};
  live = false;
  private timer = 0;
  private lastChange = Date.now();
  private listening = false;

  constructor(public id: number) {}

  load(): Promise<void> {
    this.loading = !this.data;
    return call<BoardPayload>('GET', `/boards/${this.id}`, undefined, undefined, true)
      .then((data) => {
        this.data = data;
        this.error = null;
        this.stale = false;
        this.listen();
      })
      .catch((e) => {
        this.error = e?.status || 500;
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  /** A board answer from a write (settings, team, columns, labels). */
  replace(data: BoardPayload): void {
    const keep = this.data;
    this.data = { ...data, pinned: keep?.pinned, projects: data.projects ?? keep?.projects, push: keep?.push };
    this.lastChange = Date.now();
    m.redraw();
  }

  /** A card answer from a write: put it in place and move the version on. */
  card(answer: { card: SlimCard; users: Record<string, Person>; version: number; cardCount?: number; doneCount?: number }): void {
    if (!this.data) return;
    const cards = this.data.cards.filter((c) => c.id !== answer.card.id);
    cards.push(answer.card);
    this.data.cards = sort(cards);
    Object.assign(this.data.users, answer.users || {});
    this.seen(answer.version);
    if (answer.cardCount !== undefined) this.data.board.cardCount = answer.cardCount;
    if (answer.doneCount !== undefined) this.data.board.doneCount = answer.doneCount;
    m.redraw();
  }

  removeCard(id: number): void {
    if (!this.data) return;
    this.data.cards = this.data.cards.filter((c) => c.id !== id);
    m.redraw();
  }

  /** Our own write moved the version on by one: no need to refetch for it. */
  seen(version: number): void {
    if (!this.data) return;
    if (version > this.data.board.version + 1) this.stale = true;
    this.data.board.version = Math.max(this.data.board.version, version);
    this.lastChange = Date.now();
  }

  columnCards(columnId: number): SlimCard[] {
    return (this.data?.cards || []).filter((c) => c.columnId === columnId);
  }

  /** Behind the server: fetch now, or after the drop. */
  behind(): void {
    if (this.dragging) {
      this.stale = true;
      m.redraw();
      return;
    }
    this.load();
  }

  dropped(): void {
    this.dragging = false;
    if (this.stale) this.load();
  }

  private listen(): void {
    if (this.listening) return;
    this.listening = true;
    document.addEventListener('visibilitychange', this.visibility);
    this.realtime();
    this.schedule();
  }

  stop(): void {
    this.listening = false;
    clearTimeout(this.timer);
    document.removeEventListener('visibilitychange', this.visibility);
  }

  private visibility = () => {
    if (!document.hidden) this.poll();
    else clearTimeout(this.timer);
  };

  private schedule(): void {
    clearTimeout(this.timer);
    if (!this.listening || document.hidden) return;
    const idle = Date.now() - this.lastChange > IDLE_AFTER;
    this.timer = window.setTimeout(() => this.poll(), idle ? IDLE_POLL : POLL);
  }

  poll(): void {
    if (!this.listening || !this.data) return;
    call<{ version: number }>('GET', `/boards/${this.id}/version`, undefined, undefined, true)
      .then(({ version }) => {
        if (this.data && version > this.data.board.version) {
          this.lastChange = Date.now();
          this.behind();
        }
      })
      .catch(() => {})
      .finally(() => this.schedule());
  }

  /** flarum/realtime: our events arrive on each team member's own channel. */
  private realtime(): void {
    if (!this.data?.push || !app.session.user) return;
    const flarum = (window as any).flarum;
    if (!flarum?.extensions || !('flarum-realtime' in flarum.extensions)) return;
    let rt: any;
    try {
      rt = flarum.reg.get('flarum-realtime', 'forum/RealtimeState');
    } catch (e) {
      return;
    }
    if (!rt || typeof rt.onUserChannelReady !== 'function') return;
    rt.onUserChannelReady((channel: any) => {
      this.live = true;
      channel.bind('corkboard', (e: any) => {
        if (!this.listening || !this.data || !e || e.b !== this.id) return;
        if (e.t === 'moving') {
          if (e.u === app.session.user?.id()) return;
          this.moving[e.u] = { card: e.c ?? null, name: e.n, until: Date.now() + 8000 };
          m.redraw();
          setTimeout(() => m.redraw(), 8100);
          return;
        }
        if (e.v > this.data.board.version) {
          this.lastChange = Date.now();
          this.behind();
        }
      });
      m.redraw();
    });
  }

  movingOn(cardId: number): string | null {
    const now = Date.now();
    for (const who of Object.values(this.moving)) if (who.card === cardId && who.until > now) return who.name;
    return null;
  }
}

export function sort(cards: SlimCard[]): SlimCard[] {
  return cards.sort((a, b) => a.position - b.position || a.id - b.id);
}

const boards = new Map<number, BoardState>();

export function boardState(id: number): BoardState {
  let state = boards.get(id);
  if (!state) {
    state = new BoardState(id);
    boards.set(id, state);
  }
  return state;
}

/** Leaving a board stops its polling (it starts again from the store if they come back). */
export function leaveBoard(id: number): void {
  boards.get(id)?.stop();
}
