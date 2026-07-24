import { describe, it, expect } from 'vitest';
import { contentHash, checkState } from '../src/lib/checkFreshness.js';

const ARTICLE = {
  name: 'Статья',
  primary_query: 'запрос',
  secondary_queries: ['второй'],
  blocks: [{ type: 'h2', text: 'Заголовок' }, { type: 'p', text: 'Текст' }],
};

describe('contentHash', () => {
  it('is stable for equal content', () => {
    expect(contentHash(ARTICLE)).toBe(contentHash({ ...ARTICLE }));
  });

  it('changes when blocks change', () => {
    const edited = { ...ARTICLE, blocks: [{ type: 'h2', text: 'Другой' }] };
    expect(contentHash(edited)).not.toBe(contentHash(ARTICLE));
  });

  it('changes when name or queries change', () => {
    expect(contentHash({ ...ARTICLE, name: 'Иное' })).not.toBe(contentHash(ARTICLE));
    expect(contentHash({ ...ARTICLE, primary_query: 'иной запрос' })).not.toBe(contentHash(ARTICLE));
  });

  it('ignores fields outside blocks/name/queries', () => {
    expect(contentHash({ ...ARTICLE, seo_title: 'x', region: 'msk' })).toBe(contentHash(ARTICLE));
  });
});

describe('checkState', () => {
  it('missing when the check never ran', () => {
    expect(checkState('', ARTICLE)).toBe('missing');
    expect(checkState(undefined, ARTICLE)).toBe('missing');
  });

  it('fresh when hash matches current content', () => {
    expect(checkState(contentHash(ARTICLE), ARTICLE)).toBe('fresh');
  });

  it('stale when content changed after the check', () => {
    const recorded = contentHash(ARTICLE);
    const edited = { ...ARTICLE, blocks: [...ARTICLE.blocks, { type: 'p', text: 'Новый абзац' }] };
    expect(checkState(recorded, edited)).toBe('stale');
  });
});
