import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { onColor, t, tx, write } from '../../util';
import type SettingsPage from '../SettingsPage';
import type { Label } from '../BoardState';

const COLORS = ['#4d698e', '#1971c2', '#0b7285', '#2b8a3e', '#c2410c', '#c92a2a', '#a61e4d', '#6741d9', '#5a6575'];
const LABEL_COLORS = ['#2b8a3e', '#f2c94c', '#c2410c', '#c92a2a', '#6741d9', '#1971c2', '#0b7285', '#a61e4d', '#5a6575'];

/** The general tab: name, description, colour, tag and project. */
export function general(page: SettingsPage): Mithril.Children {
  const data = page.state.data!;
  const manage = data.abilities.manage;
  const d = page.draft;
  const tags = 'flarum-tags' in ((window as any).flarum?.extensions || {}) ? (app.store.all('tags') as any[]) : [];
  return (
    <section className="CorkboardSettingsPage-section">
      <div className="Form">
        <div className="Form-group">
          <label for="ck-title">{t('settings.name')}</label>
          <input
            id="ck-title"
            className="FormControl"
            maxlength={160}
            disabled={!manage}
            value={d.title}
            oninput={(e: Event) => (d.title = (e.target as HTMLInputElement).value)}
          />
        </div>
        <div className="Form-group">
          <label for="ck-description">{t('settings.description')}</label>
          <textarea
            id="ck-description"
            className="FormControl"
            rows={3}
            maxlength={1000}
            disabled={!manage}
            value={d.description}
            oninput={(e: Event) => (d.description = (e.target as HTMLTextAreaElement).value)}
          />
        </div>
        {colorField(d, manage)}
        {tags.length ? tagField(d, manage, tags) : null}
        {data.projects && (data.projects.length || data.board.project) ? projectField(page, manage) : null}
        {manage ? saveButton(page, tags) : null}
      </div>
    </section>
  );
}

function colorField(d: Record<string, any>, manage: boolean): Mithril.Children {
  return (
    <fieldset className="Form-group">
      <legend>{t('settings.color')}</legend>
      <div className="CorkboardSwatches">
        {COLORS.map((c) => (
          <button
            type="button"
            className={classList('CorkboardSwatch', d.color === c && 'is-on')}
            style={{ background: c }}
            aria-label={c}
            aria-pressed={d.color === c ? 'true' : 'false'}
            disabled={!manage}
            onclick={() => (d.color = c)}
          />
        ))}
      </div>
    </fieldset>
  );
}

function tagField(d: Record<string, any>, manage: boolean, tags: any[]): Mithril.Children {
  return (
    <div className="Form-group">
      <label for="ck-tag">{t('settings.tag')}</label>
      <Select
        id="ck-tag"
        disabled={!manage}
        value={d.tagId}
        options={{ '': tx('settings.no_tag'), ...Object.fromEntries(tags.map((tag) => [tag.id(), tag.name()])) }}
        onchange={(value: string) => (d.tagId = value)}
      />
      <p className="helpText">{t('settings.tag_help')}</p>
    </div>
  );
}

function projectField(page: SettingsPage, manage: boolean): Mithril.Children {
  const data = page.state.data!;
  const d = page.draft;
  return (
    <div className="Form-group">
      <label for="ck-project">{t('settings.project')}</label>
      <Select
        id="ck-project"
        disabled={!manage}
        value={d.projectId}
        options={{
          '': tx('settings.no_project'),
          ...(data.board.project ? { [String(data.board.project.id)]: data.board.project.title } : {}),
          ...Object.fromEntries(data.projects!.map((p) => [String(p.id), p.title])),
        }}
        onchange={(value: string) => (d.projectId = value)}
      />
    </div>
  );
}

function saveButton(page: SettingsPage, tags: any[]): Mithril.Children {
  const data = page.state.data!;
  const d = page.draft;
  return (
    <div className="Form-group">
      <Button
        className="Button Button--primary"
        loading={page.saving}
        onclick={() =>
          page.save({
            title: d.title,
            description: d.description,
            color: d.color,
            ...(tags.length ? { tagId: d.tagId || null } : {}),
            ...(data.projects ? { projectId: d.projectId || null } : {}),
          })
        }
      >
        {t('save')}
      </Button>
    </div>
  );
}

/** The labels tab: each label's name and colour, and a new one. */
export function labels(page: SettingsPage): Mithril.Children {
  const data = page.state.data!;
  const manage = data.abilities.manage;
  return (
    <section className="CorkboardSettingsPage-section">
      <p className="helpText">{t('settings.labels_help')}</p>
      <ul className="CorkboardSettingsList">{data.labels.map((l) => labelRow(page, l, manage))}</ul>
      {manage ? (
        <Button
          className="Button"
          icon="fas fa-plus"
          onclick={() =>
            write('POST', 'labels', null, { boardId: page.state.id, name: '', color: LABEL_COLORS[data.labels.length % LABEL_COLORS.length] }).then(
              (a) => page.state.replace(a)
            )
          }
        >
          {t('settings.add_label')}
        </Button>
      ) : null}
    </section>
  );
}

function labelRow(page: SettingsPage, l: Label, manage: boolean): Mithril.Children {
  return (
    <li key={l.id}>
      <span className="CorkboardLabel CorkboardLabel--wide" style={{ background: l.color, color: onColor(l.color) }}>
        {l.name || tx('label.unnamed')}
      </span>
      {manage ? (
        <input
          className="FormControl"
          maxlength={40}
          aria-label={tx('settings.label_name')}
          placeholder={tx('settings.label_name')}
          value={l.name}
          onchange={(e: Event) => write('PATCH', 'labels', l.id, { name: (e.target as HTMLInputElement).value }).then((a) => page.state.replace(a))}
        />
      ) : null}
      {manage ? (
        <Select
          className="CorkboardSettingsList-color"
          aria-label={tx('settings.label_color')}
          value={l.color}
          options={Object.fromEntries(LABEL_COLORS.map((c) => [c, tx('color.' + c.slice(1))]))}
          onchange={(value: string) => write('PATCH', 'labels', l.id, { color: value }).then((a) => page.state.replace(a))}
        />
      ) : null}
      {manage ? (
        <Button
          className="Button Button--icon Button--link"
          icon="far fa-trash-can"
          aria-label={tx('settings.label_delete')}
          onclick={() => write('DELETE', 'labels', l.id).then(() => page.state.load())}
        />
      ) : null}
    </li>
  );
}

/** The closing tab: complete, reopen, clone, template, archive, delete. */
export function closing(page: SettingsPage): Mithril.Children {
  const data = page.state.data!;
  const b = data.board;
  const me = app.session.user ? Number(app.session.user.id()) : 0;
  const owner = b.ownerId === me || app.forum.attribute('corkboardCanModerate');
  const template = b.isTemplate ? 'settings.untemplate' : 'settings.template';
  return (
    <section className="CorkboardSettingsPage-section CorkboardSettingsPage-closing">
      {b.status === 'active' && data.abilities.manage
        ? action('settings.complete', 'settings.complete_help', 'Button', () => page.save({ status: 'completed' }))
        : null}
      {b.status !== 'active' && data.abilities.reopen
        ? action('board.reopen', 'settings.reopen_help', 'Button Button--primary', () => page.save({ status: 'active' }))
        : null}
      {app.forum.attribute('corkboardCanCreate') ? action('settings.clone', 'settings.clone_help', 'Button', () => page.clone()) : null}
      {data.abilities.template ? action(template, 'settings.template_help', 'Button', () => page.save({ isTemplate: !b.isTemplate })) : null}
      {b.status === 'active' && data.abilities.manage
        ? action('settings.archive', 'settings.archive_help', 'Button', () => page.save({ status: 'archived' }))
        : null}
      {owner ? action('settings.delete', 'settings.delete_help', 'Button Button--danger', () => page.destroy(), true) : null}
    </section>
  );
}

function action(title: string, help: string, button: string, onclick: () => unknown, danger = false): Mithril.Children {
  return (
    <div className={classList('CorkboardSettingsPage-action', danger && 'CorkboardSettingsPage-action--danger')}>
      <div>
        <strong>{t(title)}</strong>
        <p className="helpText">{t(help)}</p>
      </div>
      <Button className={button} onclick={onclick}>
        {t(title)}
      </Button>
    </div>
  );
}
