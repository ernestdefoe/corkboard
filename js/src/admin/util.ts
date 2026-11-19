import app from 'flarum/admin/app';

export const EXT = 'ernestdefoe-corkboard';
export const t = (key: string) => app.translator.trans(`${EXT}.admin.${key}`);
