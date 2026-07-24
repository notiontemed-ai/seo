import { describe, it, expect } from 'vitest';
import { INTENTS, INTENT_RU, structureBlocks, formatRepeat, structureVersion } from '../src/features/task/structureMeta.js';

describe('INTENTS', () => {
  it('exposes exactly three intents matching config keys', () => {
    expect(INTENTS.map((i) => i.key)).toEqual(['informational', 'commercial_informational', 'comparative']);
    expect(INTENT_RU.comparative).toBe('Сравнительный');
  });
});

describe('structureBlocks', () => {
  it('normalizes structure entries', () => {
    const cfg = { structure: [
      { block: 'short_answer', required: true, repeat: false },
      { block: 'h2', required: true, repeat: '2-6' },
      { block: 'faq', required: false },
    ] };
    expect(structureBlocks(cfg)).toEqual([
      { block: 'short_answer', required: true, repeat: false },
      { block: 'h2', required: true, repeat: '2-6' },
      { block: 'faq', required: false, repeat: false },
    ]);
  });
  it('returns empty array for missing structure', () => {
    expect(structureBlocks({})).toEqual([]);
    expect(structureBlocks(null)).toEqual([]);
  });
});

describe('formatRepeat', () => {
  it('renders repeat ranges with an en dash', () => {
    expect(formatRepeat('2-6')).toBe('×2–6');
    expect(formatRepeat('1-3')).toBe('×1–3');
  });
  it('returns empty for non-repeating blocks', () => {
    expect(formatRepeat(false)).toBe('');
    expect(formatRepeat('')).toBe('');
  });
});

describe('structureVersion', () => {
  it('reads version or legacy v field', () => {
    expect(structureVersion({ version: '1.0.0' })).toBe('1.0.0');
    expect(structureVersion({ v: '2.1' })).toBe('2.1');
    expect(structureVersion({})).toBe('');
  });
});
