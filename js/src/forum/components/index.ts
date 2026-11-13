/*
 * The one lazy chunk: every page and dialog. One entry on purpose: two chunks sharing a module
 * would make webpack emit a numbered shared chunk that jsDirectory doesn't publish.
 */
export { loadCore } from './core';
export { default as BoardsPage } from './BoardsPage';
export { default as BoardPage } from './BoardPage';
export { default as SettingsPage } from './SettingsPage';
export { default as MyCardsPage } from './MyCardsPage';
export { default as AddToBoardModal } from './AddToBoardModal';
