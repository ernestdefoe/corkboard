import app from 'flarum/admin/app';
import { EXT, t } from './util';

const ALL = ['public', 'members', 'team', 'private'];

/** Which visibility modes boards may use (at least one). Stored as a JSON list. */
const VisibilityChoices = {
  view() {
    const key = `${EXT}.visibilities`;
    let list: string[] = ALL;
    try {
      const parsed = JSON.parse(app.data.settings[key] || '[]');
      if (Array.isArray(parsed) && parsed.length) list = parsed;
    } catch {
      /* not a JSON list (never saved, or edited by hand): every mode, as the server reads it */
    }
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

export const visibilityChoices = () => <VisibilityChoices />;

/** The admin's reminder that due-date alerts and emails need the scheduler. */
export const scheduleNote = () => <p className="helpText CorkboardAdmin-note">{t('settings.schedule_note')}</p>;
