import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import { ago, Avatar, call, t, tx, type Group, type Person } from '../util';
import type { BoardState } from './BoardState';

interface Page {
  items: Array<{ id: number; type: string; userId: number | null; cardId: number | null; data: any; createdAt: string }>;
  more: boolean;
  users: Record<string, Person>;
  groups: Record<string, Group>;
  cards: Record<string, string>;
  columns: Record<string, string>;
}

/** History, newest first: the board's (no cardId) or one card's. Names are read when shown. */
export default class ActivityList extends Component<{ state: BoardState; cardId?: number }> {
  items: Page['items'] = [];
  users: Record<string, Person> = {};
  groups: Record<string, Group> = {};
  cards: Record<string, string> = {};
  columns: Record<string, string> = {};
  more = false;
  loading = true;

  oninit(vnode: Mithril.Vnode<{ state: BoardState; cardId?: number }, this>) {
    super.oninit(vnode);
    this.load();
  }

  load(before?: number): void {
    this.loading = true;
    const path = this.attrs.cardId ? `/cards/${this.attrs.cardId}/activity` : `/boards/${this.attrs.state.id}/activity`;
    call<Page>('GET', path, undefined, before ? { before } : {}, true)
      .then((page) => {
        this.items = before ? [...this.items, ...page.items] : page.items;
        Object.assign(this.users, page.users);
        Object.assign(this.groups, page.groups);
        Object.assign(this.cards, page.cards);
        Object.assign(this.columns, page.columns);
        this.more = page.more;
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    return (
      <div className="CorkboardActivity">
        <ul>
          {this.items.map((item) => (
            <li className="CorkboardActivity-item" key={item.id}>
              <Avatar user={item.userId ? this.users[item.userId] : null} size={24} />
              <span>
                {this.line(item)} <span className="CorkboardMuted">{ago(item.createdAt)}</span>
              </span>
            </li>
          ))}
        </ul>
        {this.loading ? <LoadingIndicator size="small" /> : null}
        {!this.loading && !this.items.length ? <p className="CorkboardMuted">{t('activity.empty')}</p> : null}
        {this.more && !this.loading ? (
          <Button className="Button Button--link" onclick={() => this.load(this.items[this.items.length - 1].id)}>
            {t('activity.older')}
          </Button>
        ) : null}
      </div>
    );
  }

  line(item: Page['items'][number]): Mithril.Children {
    const d = item.data || {};
    const who = item.userId ? this.users[item.userId]?.name || tx('deleted_user') : tx('activity.someone');
    const names = (ids: number[] = []) => ids.map((id) => this.users[id]?.name || tx('deleted_user')).join(', ');
    const card = item.cardId ? this.cards[item.cardId] || tx('activity.a_card') : d.t || tx('activity.a_card');
    const column = (id: number) => this.columns[id] || tx('activity.a_column');
    const params: Record<string, any> = {
      who,
      card: this.attrs.cardId ? tx('activity.this_card') : card,
      from: column(d.from),
      to: column(d.to),
      column: d.col ? column(d.col) : d.t || tx('activity.a_column'),
      people: names(d.u),
      group: d.g ? this.groups[d.g]?.name || '' : '',
      role: d.r ? tx('role.' + d.r) : '',
      priority: d.p ? tx('priority.' + d.p) : '',
      due: d.d ? new Date(d.d).toLocaleDateString() : tx('activity.no_date'),
    };
    if (item.type === 'member_added' || item.type === 'member_role' || item.type === 'member_removed') {
      params.people = d.u ? names([d.u]) : params.group;
    }
    const key = `activity.${item.type}`;
    const text = tx(key, params);
    return text === `ernestdefoe-corkboard.forum.${key}` ? tx('activity.changed', params) : text;
  }
}
