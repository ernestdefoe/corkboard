import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { boardUrl, call, t, tx, VISIBILITY_ICON, type Visibility } from '../util';

export interface Template {
  key: string;
  title: string;
  columns: string[];
  color?: string;
}

export interface CreateBoardModalAttrs extends IFormModalAttrs {
  template?: string;
  /** Started from a project's page: the board belongs to it. */
  projectId?: number;
  templates?: { builtIn: Template[]; custom: Template[] };
}

/** Start a board: a name, a template, and who can see it. */
export default class CreateBoardModal extends FormModal<CreateBoardModalAttrs> {
  name = '';
  template = 'simple';
  visibility: Visibility = 'team';
  templates: { builtIn: Template[]; custom: Template[] } | null = null;

  oninit(vnode: Mithril.Vnode<CreateBoardModalAttrs, this>) {
    super.oninit(vnode);
    this.template = this.attrs.template || 'simple';
    this.visibility = (app.forum.attribute('corkboardDefaultVisibility') as Visibility) || 'team';
    this.templates = this.attrs.templates || null;
    if (!this.templates) {
      call('GET', '/templates', undefined, undefined, true).then((tpl) => {
        this.templates = tpl;
        m.redraw();
      });
    }
  }

  className() {
    return 'CorkboardCreateModal';
  }

  title() {
    return tx('create.title');
  }

  content() {
    const allowed = (app.forum.attribute('corkboardVisibilities') as Visibility[]) || ['team'];
    const all = [...(this.templates?.builtIn || []), ...(this.templates?.custom || [])];
    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            <label for="ck-new-title">{t('create.name')}</label>
            <input
              id="ck-new-title"
              className="FormControl"
              maxlength={160}
              placeholder={tx('create.name_placeholder')}
              value={this.name}
              oncreate={(v: Mithril.VnodeDOM) => (v.dom as HTMLInputElement).focus()}
              oninput={(e: Event) => (this.name = (e.target as HTMLInputElement).value)}
            />
          </div>
          <fieldset className="Form-group">
            <legend>{t('create.template')}</legend>
            <div className="CorkboardTemplatePicker">
              {all.map((tpl) => (
                <label className={classList('CorkboardTemplatePicker-option', this.template === tpl.key && 'is-on')}>
                  <input type="radio" name="ck-template" checked={this.template === tpl.key} onchange={() => (this.template = tpl.key)} />
                  <span className="CorkboardTemplatePicker-name">{tpl.title}</span>
                  <span className="CorkboardTemplatePicker-columns">{tpl.columns.join(' · ')}</span>
                </label>
              ))}
            </div>
          </fieldset>
          <fieldset className="Form-group">
            <legend>{t('create.visibility')}</legend>
            <div className="CorkboardVisibilityPicker">
              {allowed
                .filter((v) => v !== 'public' || app.forum.attribute('corkboardCanPublic'))
                .map((v) => (
                  <label className={classList('CorkboardVisibilityPicker-option', this.visibility === v && 'is-on')}>
                    <input type="radio" name="ck-visibility" checked={this.visibility === v} onchange={() => (this.visibility = v)} />
                    <span className="CorkboardVisibilityPicker-name">
                      <Icon name={VISIBILITY_ICON[v]} /> {t('visibility.' + v)}
                    </span>
                    <span className="CorkboardVisibilityPicker-help">{t('visibility.' + v + '_help')}</span>
                  </label>
                ))}
            </div>
          </fieldset>
          <div className="Form-group">
            <Button className="Button Button--primary Button--block" type="submit" loading={this.loading} disabled={!this.name.trim()}>
              {t('create.submit')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;
    call('POST', '/boards', { title: this.name, template: this.template, visibility: this.visibility, projectId: this.attrs.projectId })
      .then((answer) => {
        this.hide();
        m.route.set(boardUrl(answer.board));
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
