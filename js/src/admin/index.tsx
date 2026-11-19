import app from 'flarum/admin/app';
import { registerEditorWidgets } from '../common/fofWidgets';

import { EXT } from './util';

export { default as extend } from './extend';

app.initializers.add(EXT, () => {
  registerEditorWidgets(app, EXT, [
    { key: 'corkboardMine', icon: 'fas fa-id-card', title: () => app.translator.trans(`${EXT}.lib.widget.mine_title`) },
    { key: 'corkboardDue', icon: 'fas fa-clock', title: () => app.translator.trans(`${EXT}.lib.widget.due_title`) },
  ]);
});
