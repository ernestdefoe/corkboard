import { extend } from 'flarum/common/extend';

/**
 * fof/forum-widgets-core, shared by the forum (which renders the widgets) and
 * the admin (whose layout editor places them).
 *
 * 🚨 OPT-IN. FoF shows any registered widget that no saved layout mentions, in
 * its default section, so a widget registered only on the forum side appeared
 * in a right-hand column on every forum with FoF installed, even one whose
 * layout had no widgets at all, and FoF's editor could not take it out (the
 * same bug as Cadence's, Discuss d/39992/14). Now a widget shows only once the
 * admin has dragged it into a section and saved; until then it waits in the
 * editor's list of available widgets.
 */

/** A module of fof/forum-widgets-core, at runtime — never a build-time import. */
export function fof(path: string): any {
  const mod = (globalThis as any).flarum?.reg?.get?.('fof-forum-widgets-core', path);
  return mod?.default ?? mod;
}

export function hasFofWidgets(): boolean {
  const flarum = (globalThis as any).flarum;
  return !!flarum?.extensions && 'fof-forum-widgets-core' in flarum.extensions;
}

/** Whether the saved layout places this widget in a section. */
export function placed(app: any, extension: string, key: string): boolean {
  return (app.widgets?.config?.instances || []).some((i: any) => i?.id === `${extension}:${key}`);
}

export interface EditorWidget {
  key: string;
  icon: string;
  title: () => any;
  placement?: string;
}

/**
 * Offer the widgets to FoF's layout editor, which lists only widgets registered on the admin side
 * too, through FoF's own Widgets extender. They are registered as the editor opens (patchEditor).
 */
export function registerEditorWidgets(app: any, extension: string, widgets: EditorWidget[]): void {
  const Widgets = fof('common/extend/Widgets');
  if (!Widgets) return;

  patchEditor(extension, widgets, () => {
    const manager = app.widgets;
    if (!manager || manager.widgets.some((w: any) => w.extension === extension)) return;
    const add = new Widgets();
    widgets.forEach((w, position) =>
      add.add({
        key: w.key,
        component: { component: () => null },
        title: w.title,
        icon: w.icon,
        isDisabled: false,
        isUnique: true,
        placement: w.placement || 'end',
        position,
      })
    );
    add.extend(app, extension);
  });
}

/**
 * 🚨 The one place Corkboard touches FoF Widgets' internals: its editor component's `oninit` and
 * the editor's own `config` (`{ instances, disabled }`), neither a documented API, so either may
 * change in any FoF release. As the editor opens, the widgets are registered ($register) and any
 * no saved layout places is added to `config.disabled`, so the editor lists it as available
 * instead of drawing it in a section (FoF would draw it placed, while the forum rightly shows
 * nothing until it is saved). If the shape changes this only stops doing that: the widgets still
 * register and show placed in the editor until saved.
 *
 * An opt-in ("disabled until placed") option in fof/forum-widgets-core would replace this whole
 * function: then register the widgets with it and delete this.
 */
function patchEditor(extension: string, widgets: EditorWidget[], register: () => void): void {
  const WidgetEditor = fof('admin/components/WidgetEditor');
  if (!WidgetEditor) return;

  extend(WidgetEditor.prototype, 'oninit', function (this: any) {
    register();
    const config = this.config;
    if (!config || typeof config !== 'object') return;
    const disabled: string[] = Array.isArray(config.disabled) ? config.disabled : [];
    const instances: any[] = Array.isArray(config.instances) ? config.instances : [];
    const unplaced = widgets.map((w) => `${extension}:${w.key}`).filter((id) => !disabled.includes(id) && !instances.some((i: any) => i?.id === id));
    config.disabled = [...disabled, ...unplaced];
  });
}
