import { describe, it, expect } from 'vitest';
import { translitCode, guessArticleType, topicToArticlePatch, caseStudyBlock } from '../src/lib/caseEntry.js';

const TYPES = [
  { xml_id: 'informational', value: 'Информационная' },
  { xml_id: 'diagnostics', value: 'Диагностика' },
  { xml_id: 'comparison', value: 'Сравнение' },
];

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

describe('guessArticleType', () => {
  it('detects comparison', () => {
    expect(guessArticleType('МРТ или КТ: что выбрать', TYPES)).toBe('comparison');
  });
  it('detects diagnostics', () => {
    expect(guessArticleType('Как проходит МРТ позвоночника', TYPES)).toBe('diagnostics');
  });
  it('falls back to informational when nothing matches', () => {
    expect(guessArticleType('Просто текст без маркеров', TYPES)).toBe('informational');
  });
  it('never returns xml_id absent from the dictionary', () => {
    expect(guessArticleType('лечение боли', [{ xml_id: 'informational' }])).toBe('informational');
    expect(guessArticleType('лечение боли', [])).toBe('');
  });
});

describe('topicToArticlePatch', () => {
  const topic = { title: 'Боль в пояснице', primary_query: 'болит поясница', fit: 'кейс о диагностике' };
  const caseData = { transcript: 'Пациент 45 лет…', summary: 'Кейс о боли' };

  it('prefills task fields from topic', () => {
    const patch = topicToArticlePatch(topic, caseData, TYPES);
    expect(patch.name).toBe('Боль в пояснице');
    expect(patch.primary_query).toBe('болит поясница');
    expect(patch.code).toBe('bol-v-poyasnitse');
    expect(patch.article_type).toBe('diagnostics');
    expect(patch.case_transcript).toBe('Пациент 45 лет…');
    expect(patch.case_summary).toBe('Кейс о боли');
  });
});

describe('caseStudyBlock', () => {
  it('builds case_study block from transcript', () => {
    const block = caseStudyBlock({ transcript: 'Расшифровка' });
    expect(block.type).toBe('case_study');
    expect(block.situation).toBe('Расшифровка');
  });
});
