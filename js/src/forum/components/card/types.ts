import type { Person, SlimCard } from '../../util';

/** A card as the dialog loads it: everything the slim board payload leaves out. */
export interface Detail extends SlimCard {
  boardId: number;
  archivedAt: string | null;
  completedBy: number | null;
  createdAt: string | null;
  descriptionHtml: string | null;
  description: string | null;
  checklist: ChecklistItem[];
  comments: CardComment[];
  commentsMore: boolean;
  watching: boolean | null;
  watcherCount: number;
  discussion: null | LinkedDiscussion;
  users: Record<string, Person>;
  abilities: { comment: boolean; edit: boolean; manage: boolean };
}

export interface ChecklistItem {
  id: number;
  text: string;
  isDone: boolean;
  userId: number | null;
}

export interface CardComment {
  id: number;
  userId: number | null;
  html: string;
  source: string | null;
  createdAt: string;
  editedAt: string | null;
  canEdit: boolean;
  canDelete: boolean;
}

export interface LinkedDiscussion {
  id: number;
  title: string;
  slug: string;
  replyCount: number;
  lastReplies: Array<{ id: number; number: number; createdAt: string; user: Person | null; excerpt: string | null }>;
}
