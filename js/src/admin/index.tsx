import app from 'flarum/admin/app';
import { registerEditorWidgets } from '../common/fofWidgets';

const EXT = 'ernestdefoe-corkboard';
const t = (key: string) => app.translator.trans(`${EXT}.admin.${key}`);

app.initializers.add(EXT, () => {
  app.registry
    .for(EXT)
    .registerSetting(() => <VisibilityChoices />)
    .registerSetting({
      setting: `${EXT}.default_visibility`,
      type: 'select',
      label: t('settings.default_visibility'),
      help: t('settings.default_visibility_help'),
      options: {
        public: t('visibility.public'),
        members: t('visibility.members'),
        team: t('visibility.team'),
        private: t('visibility.private'),
      },
      default: 'team',
    })
    .registerSetting({
      setting: `${EXT}.due_soon_hours`,
      type: 'select',
      label: t('settings.due_soon'),
      help: t('settings.due_soon_help'),
      options: { 2: t('settings.hours_2'), 24: t('settings.hours_24'), 72: t('settings.hours_72') },
      default: '24',
    })
    .registerSetting({
      setting: `${EXT}.min_posts`,
      type: 'number',
      min: 0,
      label: t('settings.min_posts'),
      help: t('settings.min_posts_help'),
    })
    .registerSetting({
      setting: `${EXT}.nav`,
      type: 'boolean',
      label: t('settings.nav'),
    })
    .registerSetting(() => <p className="helpText CorkboardAdmin-note">{t('settings.schedule_note')}</p>)
    .registerPermission({ icon: 'fas fa-table-columns', label: t('permissions.view'), permission: 'corkboard.view', allowGuest: true }, 'view')
    .registerPermission({ icon: 'fas fa-plus', label: t('permissions.create'), permission: 'corkboard.create' }, 'start')
    .registerPermission({ icon: 'fas fa-globe', label: t('permissions.create_public'), permission: 'corkboard.createPublic' }, 'start')
    .registerPermission({ icon: 'far fa-copy', label: t('permissions.templates'), permission: 'corkboard.useTemplates' }, 'start')
    .registerPermission({ icon: 'fas fa-table-columns', label: t('permissions.moderate'), permission: 'corkboard.moderate' }, 'moderate');

  registerEditorWidgets(app, EXT, [
    { key: 'corkboardMine', icon: 'fas fa-id-card', title: () => app.translator.trans(`${EXT}.lib.widget.mine_title`) },
    { key: 'corkboardDue', icon: 'fas fa-clock', title: () => app.translator.trans(`${EXT}.lib.widget.due_title`) },
  ]);
});

const ALL = ['public', 'members', 'team', 'private'];

/** Which visibility modes boards may use (at least one). Stored as a JSON list. */
const VisibilityChoices = {
  view() {
    const key = `${EXT}.visibilities`;
    let list: string[] = ALL;
    try {
      const parsed = JSON.parse(app.data.settings[key] || '[]');
      if (Array.isArray(parsed) && parsed.length) list = parsed;
    } catch (e) {}
    const toggle = (v: string) => {
      const next = list.includes(v) ? list.filter((x) => x !== v) : [...list, v];
      if (!next.length) return;
      const value = JSON.stringify(ALL.filter((x) => next.includes(x)));
      app.data.settings[key] = value;
      app
        .request({ method: 'POST', url: app.forum.attribute('apiUrl') + '/settings', body: { [key]: value } })
        .then(() => app.alerts.show({ type: 'success' }, app.translator.trans('core.admin.settings.saved_message')));
    };
    return (
      <div className="Form-group">
        <label>{t('settings.visibilities')}</label>
        <p className="helpText">{t('settings.visibilities_help')}</p>
        {ALL.map((v) => (
          <label className="checkbox">
            <input type="checkbox" checked={list.includes(v)} onchange={() => toggle(v)} />
            {t('visibility.' + v)}
          </label>
        ))}
      </div>
    );
  },
};
