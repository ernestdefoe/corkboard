import app from 'flarum/forum/app';
import { fof, hasFofWidgets, placed } from '../../common/fofWidgets';
import CorkboardWidget, { type Kind } from './Widget';

/*
 * The widgets in every host that's present, found at runtime (never a build-time import):
 * fof/forum-widgets-core (Bespoke 2.9+ shows those in its zones by itself) and Page Builder.
 * FoF widgets are opt-in: nothing shows until the admin places one in FoF's layout editor.
 */

export const EXT = 'ernestdefoe-corkboard';

export const WIDGETS: Array<{ kind: Kind; key: string; icon: string }> = [
  { kind: 'mine', key: 'corkboardMine', icon: 'fas fa-id-card' },
  { kind: 'due', key: 'corkboardDue', icon: 'fas fa-clock' },
  { kind: 'progress', key: 'corkboardProgress', icon: 'fas fa-bars-progress' },
];

export function registerFof(): void {
  if (!hasFofWidgets()) return;
  const Widgets = fof('common/extend/Widgets');
  const WidgetBase = fof('common/components/Widget');
  if (!Widgets || !WidgetBase || !(app as any).widgets) return;

  const add = new Widgets();
  WIDGETS.filter((w) => w.kind !== 'progress').forEach(({ kind, key, icon }, position) => {
    class FofCorkboard extends WidgetBase {
      className() {
        return `CorkboardFofWidget CorkboardFofWidget--${kind}`;
      }
      icon() {
        return icon;
      }
      title() {
        return app.translator.trans(`${EXT}.lib.widget.${kind}_title`);
      }
      content() {
        return <CorkboardWidget kind={kind} host="fof" bare />;
      }
    }
    add.add({
      key,
      component: FofCorkboard,
      // 🚨 Opt-in, and no box for guests (the widget is the viewer's own).
      isDisabled: () => !placed(app, EXT, key) || !app.session.user,
      isUnique: true,
      placement: 'end',
      position,
    });
  });
  add.extend(app, EXT);
}

export function registerPageBuilder(): void {
  // Page Builder 3.10+ drains this queue on every lookup, so initializer order doesn't matter.
  const blocks = WIDGETS.map(({ kind }) => ({
    type: `corkboard-${kind}`,
    component: { view: (vnode: any) => <CorkboardWidget kind={kind} host="page-builder" settings={vnode.attrs?.settings || {}} /> },
  }));
  const queue = ((globalThis as any).PageBuilderBlockQueue = (globalThis as any).PageBuilderBlockQueue || []);
  queue.push(...blocks);
  const pb = (app as any).pageBuilder;
  if (pb?.registerBlock) blocks.forEach(({ type, component }) => pb.registerBlock(type, component));
}
