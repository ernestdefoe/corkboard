import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { boardUrl, t, templateList, tx, VISIBILITY_ICON, write, type Template, type Visibility } from '../util';

export type { Template };

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
      templateList()
        .then((tpl) => {
          this.templates = tpl;
          m.redraw();
        })
        .catch(() => {
          /* a quiet read: the picker keeps what it has */
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
          {this.templatePicker()}
          {this.visibilityPicker()}
          <div className="Form-group">
            <Button className="Button Button--primary Button--block" type="submit" loading={this.loading} disabled={!this.name.trim()}>
              {t('create.submit')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  templatePicker(): Mithril.Children {
    const all = [...(this.templates?.builtIn || []), ...(this.templates?.custom || [])];
    return (
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
    );
  }

  visibilityPicker(): Mithril.Children {
    const allowed = (app.forum.attribute('corkboardVisibilities') as Visibility[]) || ['team'];
    return (
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
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;
    write('POST', 'boards', null, { title: this.name, template: this.template, visibility: this.visibility, projectId: this.attrs.projectId })
      .then((answer) => {
        this.hide();
        m.route.set(boardUrl(answer.board));
      })
      .catch(() => {
        /* write() already showed the error */
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
