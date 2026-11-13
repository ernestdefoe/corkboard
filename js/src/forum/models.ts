import Model from 'flarum/common/Model';

/** Notification subjects only (Corkboard's pages read its own JSON API). */
export class CorkboardCard extends Model {
  title = Model.attribute<string>('title');
  boardId = Model.attribute<number>('boardId');
  boardTitle = Model.attribute<string>('boardTitle');
  boardSlug = Model.attribute<string>('boardSlug');
}

export class CorkboardBoard extends Model {
  title = Model.attribute<string>('title');
  slug = Model.attribute<string>('slug');
}
