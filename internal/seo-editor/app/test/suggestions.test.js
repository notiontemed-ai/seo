import { describe, it, expect } from 'vitest';
import { filterSuggestions } from '../src/lib/suggestions.js';

const ALLOWED = ['name', 'code', 'primary_query'];

describe('filterSuggestions', () => {
  it('keeps suggestions with allowed field_id and non-empty value', () => {
    const { kept } = filterSuggestions([{ field_id: 'name', value: 'Заголовок', label: 'Название' }], ALLOWED);
    expect(kept).toHaveLength(1);
    expect(kept[0].field_id).toBe('name');
  });

  it('drops suggestions without field_id', () => {
    const { kept, dropped } = filterSuggestions([{ value: 'x' }], ALLOWED);
    expect(kept).toHaveLength(0);
    expect(dropped).toHaveLength(1);
  });

  it('drops suggestions with field_id outside allowlist', () => {
    const { kept } = filterSuggestions([{ field_id: 'detail_html', value: 'x' }], ALLOWED);
    expect(kept).toHaveLength(0);
  });

  it('drops suggestions with empty or null value', () => {
    const { kept } = filterSuggestions(
      [
        { field_id: 'name', value: '' },
        { field_id: 'code', value: null },
      ],
      ALLOWED
    );
    expect(kept).toHaveLength(0);
  });

  it('handles non-array input', () => {
    expect(filterSuggestions(undefined, ALLOWED).kept).toHaveLength(0);
    expect(filterSuggestions(null, ALLOWED).kept).toHaveLength(0);
  });

  it('separates kept and dropped from a mixed list', () => {
    const { kept, dropped } = filterSuggestions(
      [
        { field_id: 'name', value: 'ok' },
        { field_id: 'name' }, // no value
        { value: 'no field' }, // no field_id
        { field_id: 'code', value: 'ok2' },
      ],
      ALLOWED
    );
    expect(kept).toHaveLength(2);
    expect(dropped).toHaveLength(2);
  });
});
