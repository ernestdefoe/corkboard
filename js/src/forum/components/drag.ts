/**
 * Dragging a card with a mouse or a finger (the keyboard has its own path in BoardPage).
 *
 * Built from Bug Tracker's engine, for the same reasons:
 * - 🚨 pointer events, not HTML5 drag and drop: `draggable` never fires on touch, so a board built
 *   on it works for whoever tests on a laptop and is dead on a phone;
 * - 🚨 the ghost is `position: fixed`, set inline: it's a clone of the card, so any class rule that
 *   positions the card would also position the ghost.
 *
 * Added for phones:
 * - a finger has to rest 180 ms on a card before it lifts, so swiping the board still scrolls;
 * - 🚨 once it lifts, a non-passive touchmove cancels the browser's own pan, or the browser takes
 *   the gesture over half way (pointercancel) and the card is dropped where it was;
 * - near the left or right edge the column strip scrolls on, and near a column's top or bottom
 *   the column scrolls, so a card can travel anywhere on a 390-pixel screen.
 */

export interface DropTarget {
  columnId: number | null;
  /** The card it lands right after; null for the top of the column. */
  afterId: number | null;
}

export interface DragOptions {
  onStart: (cardId: number) => void;
  onMove: (target: DropTarget) => void;
  onDrop: (cardId: number, target: DropTarget) => void;
  onCancel: () => void;
}

const COLUMN = '[data-ck-column]';
const LIST = '[data-ck-list]';
const CARD = '[data-ck-card]';
const STRIP = '[data-ck-strip]';

const HOLD_MS = 180;
const SLOP_PX = 5;
const EDGE_PX = 48;

export class BoardDrag {
  private ghost: HTMLElement | null = null;
  private source: HTMLElement | null = null;
  private cardId = 0;
  private startX = 0;
  private startY = 0;
  private x = 0;
  private y = 0;
  private offsetX = 0;
  private offsetY = 0;
  private armed = false;
  private dragging = false;
  private hold = 0;
  private frame = 0;
  private target: DropTarget = { columnId: null, afterId: null };

  constructor(
    private root: HTMLElement,
    private options: DragOptions
  ) {
    root.addEventListener('pointerdown', this.down);
    root.addEventListener('touchmove', this.touchMove, { passive: false });
    root.addEventListener('contextmenu', this.menu);
  }

  destroy(): void {
    this.root.removeEventListener('pointerdown', this.down);
    this.root.removeEventListener('touchmove', this.touchMove);
    this.root.removeEventListener('contextmenu', this.menu);
    this.cleanup();
  }

  private down = (e: PointerEvent) => {
    if (e.button !== 0 && e.pointerType === 'mouse') return;
    const el = e.target as HTMLElement;
    const card = el?.closest?.(CARD) as HTMLElement | null;
    // A link, button or field inside a card is itself, not a handle.
    if (!card || el.closest('a, button, input, textarea, select, [contenteditable]')) return;

    this.source = card;
    this.cardId = Number(card.getAttribute('data-ck-card'));
    this.startX = this.x = e.clientX;
    this.startY = this.y = e.clientY;
    const rect = card.getBoundingClientRect();
    this.offsetX = e.clientX - rect.left;
    this.offsetY = e.clientY - rect.top;
    this.armed = true;

    window.addEventListener('pointermove', this.move, { passive: false });
    window.addEventListener('pointerup', this.up);
    window.addEventListener('pointercancel', this.cancel);

    if (e.pointerType !== 'mouse') this.hold = window.setTimeout(() => this.begin(), HOLD_MS);
  };

  private begin(): void {
    if (this.dragging || !this.source) return;
    this.dragging = true;
    const rect = this.source.getBoundingClientRect();
    const ghost = this.source.cloneNode(true) as HTMLElement;
    Object.assign(ghost.style, {
      position: 'fixed',
      left: rect.left + 'px',
      top: rect.top + 'px',
      width: rect.width + 'px',
      margin: '0',
      pointerEvents: 'none',
      zIndex: '2147483000',
      transform: 'rotate(2deg) scale(1.02)',
      boxShadow: '0 14px 30px rgba(0,0,0,.3)',
    });
    ghost.classList.add('CorkboardCard--ghost');
    ghost.removeAttribute('data-ck-card');
    document.body.appendChild(ghost);
    this.ghost = ghost;
    this.source.classList.add('CorkboardCard--lifted');
    document.body.classList.add('CorkboardDragging');
    navigator.vibrate?.(10);
    this.options.onStart(this.cardId);
    this.place();
    this.frame = requestAnimationFrame(this.tick);
  }

  private move = (e: PointerEvent) => {
    if (!this.armed) return;
    this.x = e.clientX;
    this.y = e.clientY;
    if (!this.dragging) {
      const far = Math.abs(e.clientX - this.startX) > SLOP_PX || Math.abs(e.clientY - this.startY) > SLOP_PX;
      if (e.pointerType === 'mouse') {
        if (far) this.begin();
      } else if (far) {
        // Moved before the hold: a scroll, not a pick-up.
        this.cleanup();
      }
      return;
    }
    e.preventDefault();
    this.place();
  };

  private touchMove = (e: TouchEvent) => {
    if (this.dragging && e.cancelable) e.preventDefault();
  };

  private menu = (e: Event) => {
    if (this.armed) e.preventDefault();
  };

  private place(): void {
    if (this.ghost) {
      this.ghost.style.left = this.x - this.offsetX + 'px';
      this.ghost.style.top = this.y - this.offsetY + 'px';
    }
    const target = this.resolve();
    if (target.columnId !== this.target.columnId || target.afterId !== this.target.afterId) {
      this.target = target;
      this.options.onMove(target);
    }
  }

  /** Scroll the strip and the column under the finger while it rests near an edge. */
  private tick = () => {
    if (!this.dragging) return;
    const strip = this.root.querySelector(STRIP) as HTMLElement | null;
    if (strip) {
      const r = strip.getBoundingClientRect();
      if (this.x < r.left + EDGE_PX) strip.scrollLeft -= 8;
      else if (this.x > r.right - EDGE_PX) strip.scrollLeft += 8;
    }
    const list = this.columnAt()?.querySelector(LIST) as HTMLElement | null;
    if (list) {
      const r = list.getBoundingClientRect();
      if (this.y < r.top + EDGE_PX) list.scrollTop -= 10;
      else if (this.y > r.bottom - EDGE_PX) list.scrollTop += 10;
    }
    this.place();
    this.frame = requestAnimationFrame(this.tick);
  };

  private columnAt(): HTMLElement | null {
    const columns = Array.from(this.root.querySelectorAll(COLUMN)) as HTMLElement[];
    return (
      columns.find((c) => {
        const r = c.getBoundingClientRect();
        return this.x >= r.left && this.x <= r.right;
      }) || null
    );
  }

  /** Which column, and which card it would sit after, measured from the live layout. */
  private resolve(): DropTarget {
    const column = this.columnAt();
    if (!column) return { columnId: null, afterId: null };
    const columnId = Number(column.getAttribute('data-ck-column'));
    const cards = (Array.from(column.querySelectorAll(CARD)) as HTMLElement[]).filter((c) => Number(c.getAttribute('data-ck-card')) !== this.cardId);
    let afterId: number | null = null;
    for (const card of cards) {
      const r = card.getBoundingClientRect();
      if (this.y > r.top + r.height / 2) afterId = Number(card.getAttribute('data-ck-card'));
      else break;
    }
    return { columnId, afterId };
  }

  private up = () => {
    const was = this.dragging;
    const target = this.target;
    const id = this.cardId;
    this.cleanup();
    if (was && target.columnId !== null) this.options.onDrop(id, target);
    else if (was) this.options.onCancel();
  };

  private cancel = () => {
    const was = this.dragging;
    this.cleanup();
    if (was) this.options.onCancel();
  };

  private cleanup(): void {
    clearTimeout(this.hold);
    cancelAnimationFrame(this.frame);
    window.removeEventListener('pointermove', this.move);
    window.removeEventListener('pointerup', this.up);
    window.removeEventListener('pointercancel', this.cancel);
    this.ghost?.remove();
    this.ghost = null;
    this.source?.classList.remove('CorkboardCard--lifted');
    this.source = null;
    document.body.classList.remove('CorkboardDragging');
    this.armed = false;
    this.dragging = false;
    this.target = { columnId: null, afterId: null };
  }
}
