import { describe, it, expect } from 'vitest';
import { buildProperties, buildDraftPayload, collectSources } from '../src/lib/publishPayload.js';

const ARTICLE = {
  name: 'Тест',
  code: 'test',
  section_id: 432,
  preview_text: 'Анонс',
  primary_query: 'боль в спине',
  secondary_queries: ['лечение спины'],
  search_intent: 'informational',
  article_type: 'diagnostics',
  region: '',
  author_id: 65001,
  medical_reviewer_id: 0,
  structure_id: '',
  show_form: 'Y',
  form_id: 'callback',
  form_button_text: '',
  seo_title: 'SEO заголовок',
  meta_description: 'Описание',
  short_answer: 'Краткий ответ',
  featured_image_alt: 'Альт текст',
  related_articles: [101],
  related_articles_v2: [201, 202],
  related_services: [],
  related_clinics: [301],
  medical_reviewed_at: '2026-07-24',
  content_updated_at: '2026-07-24',
  blocks: [
    { type: 'h2', text: 'Заголовок' },
    { type: 'sources', items: ['https://pubmed.gov/1', ' ', 'https://who.int/2'] },
  ],
};

describe('collectSources', () => {
  it('collects trimmed non-empty items from sources blocks', () => {
    expect(collectSources(ARTICLE.blocks)).toEqual(['https://pubmed.gov/1', 'https://who.int/2']);
  });
  it('returns empty array without sources blocks', () => {
    expect(collectSources([{ type: 'p', text: 'x' }])).toEqual([]);
  });
});

describe('buildProperties (этап 8.1)', () => {
  const props = buildProperties(ARTICLE, []);

  it('includes restored stage 8.1 properties', () => {
    expect(props.SHORT_ANSWER).toBe('Краткий ответ');
    expect(props.SOURCES).toEqual(['https://pubmed.gov/1', 'https://who.int/2']);
    expect(props.RELATED_ARTICLES).toEqual([101]);
    expect(props.RELATED_ARTICLES_V2).toEqual([201, 202]);
    expect(props.RELATED_CLINICS).toEqual([301]);
    expect(props.MEDICAL_REVIEWED_AT).toBe('2026-07-24');
    expect(props.CONTENT_UPDATED_AT).toBe('2026-07-24');
    expect(props.FEATURED_IMAGE_ALT).toBe('Альт текст');
  });

  it('omits empty values', () => {
    expect(props).not.toHaveProperty('RELATED_SERVICES');
    expect(props).not.toHaveProperty('REGION');
    expect(props).not.toHaveProperty('MEDICAL_REVIEWER');
  });

  it('never sets ARTICLE_TYPE (ТЗ 7)', () => {
    expect(props).not.toHaveProperty('ARTICLE_TYPE');
  });
});

describe('buildDraftPayload', () => {
  it('adds seo meta when filled', () => {
    const payload = buildDraftPayload(ARTICLE, []);
    expect(payload.seo).toEqual({ title: 'SEO заголовок', description: 'Описание' });
    expect(payload.article_content.blocks).toHaveLength(2);
  });

  it('omits seo when empty', () => {
    const payload = buildDraftPayload({ ...ARTICLE, seo_title: '', meta_description: '  ' }, []);
    expect(payload).not.toHaveProperty('seo');
  });
});
