import Extend from 'flarum/common/extenders';
import { scheduleNote, visibilityChoices } from './VisibilityChoices';
import { EXT, t } from './util';

// Defaults are the server's (extend.php registers them); the admin page reads them from there.
export default [
  new Extend.Admin()
    .customSetting(visibilityChoices)
    .setting(() => ({
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
    }))
    .setting(() => ({
      setting: `${EXT}.due_soon_hours`,
      type: 'select',
      label: t('settings.due_soon'),
      help: t('settings.due_soon_help'),
      options: { 2: t('settings.hours_2'), 24: t('settings.hours_24'), 72: t('settings.hours_72') },
    }))
    .setting(() => ({
      setting: `${EXT}.min_posts`,
      type: 'number',
      min: 0,
      label: t('settings.min_posts'),
      help: t('settings.min_posts_help'),
    }))
    .setting(() => ({
      setting: `${EXT}.nav`,
      type: 'boolean',
      label: t('settings.nav'),
    }))
    .customSetting(scheduleNote)
    .permission(() => ({ icon: 'fas fa-table-columns', label: t('permissions.view'), permission: 'corkboard.view', allowGuest: true }), 'view')
    .permission(() => ({ icon: 'fas fa-plus', label: t('permissions.create'), permission: 'corkboard.create' }), 'start')
    .permission(() => ({ icon: 'fas fa-globe', label: t('permissions.create_public'), permission: 'corkboard.createPublic' }), 'start')
    .permission(() => ({ icon: 'far fa-copy', label: t('permissions.templates'), permission: 'corkboard.useTemplates' }), 'start')
    .permission(() => ({ icon: 'fas fa-table-columns', label: t('permissions.moderate'), permission: 'corkboard.moderate' }), 'moderate'),
];
