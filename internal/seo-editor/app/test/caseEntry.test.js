import { describe, it, expect } from 'vitest';
import { translitCode, topicToArticlePatch, caseStudyBlock } from '../src/lib/caseEntry.js';

describe('translitCode', () => {
  it('transliterates russian titles to kebab-case', () => {
    expect(translitCode('Боль в спине: что делать?')).toBe('bol-v-spine-chto-delat');
  });
  it('keeps latin and digits', () => {
    expect(translitCode('МРТ 3 Тесла')).toBe('mrt-3-tesla');
  });
  it('handles soft signs and combined letters', () => {
    expect(translitCode('Жёлчный пузырь')).toBe('zhelchnyy-puzyr');
  });
  it('returns empty string for empty input', () => {
    expect(translitCode('')).toBe('');
  });
});

describe('topicToArticlePatch', () => {
  const topic = {
    title: 'Боль в пояснице',
    primary_query: 'болит поясница',
    secondary_queries: ['боль в спине', ' лечение поясницы ', ''],
    fit: 'кейс о диагностике',
  };
  const caseData = { transcript: 'Пациент 45 лет…', summary: 'Кейс о боли' };

  it('prefills task fields from topic', () => {
    const patch = topicToArticlePatch(topic, caseData);
    expect(patch.name).toBe('Боль в пояснице');
    expect(patch.primary_query).toBe('болит поясница');
    expect(patch.code).toBe('bol-v-poyasnitse');
    expect(patch.case_transcript).toBe('Пациент 45 лет…');
    expect(patch.case_summary).toBe('Кейс о боли');
  });

  it('carries trimmed non-empty secondary queries (ТЗ 8)', () => {
    const patch = topicToArticlePatch(topic, caseData);
    expect(patch.secondary_queries).toEqual(['боль в спине', 'лечение поясницы']);
  });

  it('does not set article_type (ТЗ 7)', () => {
    const patch = topicToArticlePatch(topic, caseData);
    expect(patch).not.toHaveProperty('article_type');
  });

  it('handles topics without secondary queries', () => {
    const patch = topicToArticlePatch({ title: 'Тема', primary_query: 'запрос' }, caseData);
    expect(patch.secondary_queries).toEqual([]);
  });
});

describe('caseStudyBlock', () => {
  it('builds case_study block from transcript', () => {
    const block = caseStudyBlock({ transcript: 'Расшифровка' });
    expect(block.type).toBe('case_study');
    expect(block.situation).toBe('Расшифровка');
  });
});
