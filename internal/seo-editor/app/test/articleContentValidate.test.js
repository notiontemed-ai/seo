import { describe, it, expect } from 'vitest';
import { validateArticleContent } from '../src/lib/articleContent.js';

const VALID = {
  schema_version: '2.0',
  blocks: [
    { type: 'h2', text: 'Заголовок' },
    { type: 'p', text: 'Абзац' },
  ],
};

describe('validateArticleContent', () => {
  it('accepts a minimal valid document', () => {
    const res = validateArticleContent(VALID);
    expect(res.valid).toBe(true);
    expect(res.blocks).toHaveLength(2);
    expect(res.warnings).toHaveLength(0);
  });

  it('rejects content without blocks', () => {
    expect(validateArticleContent(null).valid).toBe(false);
    expect(validateArticleContent({}).valid).toBe(false);
  });

  it('drops unknown block types with a warning', () => {
    const res = validateArticleContent({
      blocks: [...VALID.blocks, { type: 'iframe_embed', src: 'x' }],
    });
    expect(res.valid).toBe(true);
    expect(res.blocks).toHaveLength(2);
    expect(res.warnings.some((w) => w.includes('iframe_embed'))).toBe(true);
  });

  it('requires at least one h2 and one p', () => {
    const res = validateArticleContent({ blocks: [{ type: 'p', text: 'x' }] });
    expect(res.valid).toBe(false);
    expect(res.errors.some((e) => e.includes('h2'))).toBe(true);
  });

  it('drops blocks with wrong field shapes', () => {
    const res = validateArticleContent({
      blocks: [...VALID.blocks, { type: 'list', ordered: false, items: 'не массив' }],
    });
    expect(res.blocks).toHaveLength(2);
    expect(res.warnings.some((w) => w.includes('items'))).toBe(true);
  });

  it('drops expert_opinion with doctor_id outside the dictionary', () => {
    const res = validateArticleContent(
      { blocks: [...VALID.blocks, { type: 'expert_opinion', doctor_id: 999, quote: 'Цитата' }] },
      { doctorIds: [1, 2, 3] }
    );
    expect(res.blocks).toHaveLength(2);
    expect(res.warnings.some((w) => w.includes('doctor_id'))).toBe(true);
  });

  it('keeps expert_opinion with a known doctor_id', () => {
    const res = validateArticleContent(
      { blocks: [...VALID.blocks, { type: 'expert_opinion', doctor_id: 2, quote: 'Цитата' }] },
      { doctorIds: [1, 2, 3] }
    );
    expect(res.blocks).toHaveLength(3);
  });
});
