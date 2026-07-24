import { describe, it, expect, beforeEach } from 'vitest';
import { useStore } from '../src/store/useStore.js';
import { defaultBlock } from '../src/lib/articleContent.js';

function reset() {
  useStore.setState({
    article: {
      name: '', code: '', source: 'new', element_id: 0, section_id: 0, preview_text: '',
      primary_query: '', secondary_queries: [], search_intent: '', article_type: '', region: '',
      author_id: 0, medical_reviewer_id: 0, structure_id: '', show_form: 'N', form_id: '',
      form_button_text: '', blocks: [],
    },
    dirty: false,
  });
}

const blocks = () => useStore.getState().article.blocks;
const store = () => useStore.getState();

describe('defaultBlock', () => {
  it('creates typed defaults per block kind', () => {
    expect(defaultBlock('h2')).toEqual({ type: 'h2', text: '' });
    expect(defaultBlock('list')).toEqual({ type: 'list', ordered: false, items: [''] });
    const stat = defaultBlock('stats_highlight');
    expect(stat.type).toBe('stats_highlight');
    expect(stat.source_index).toBe(0);
    const symptoms = defaultBlock('symptoms');
    expect(symptoms.items[0]).toEqual({ text: '', red_flag: false });
  });
});

describe('blocks store operations', () => {
  beforeEach(reset);

  it('addBlock appends by default and inserts at index', () => {
    store().addBlock('h2');
    store().addBlock('p');
    expect(blocks().map((b) => b.type)).toEqual(['h2', 'p']);
    store().addBlock('h3', 1);
    expect(blocks().map((b) => b.type)).toEqual(['h2', 'h3', 'p']);
    expect(store().dirty).toBe(true);
  });

  it('updateBlock patches a block field', () => {
    store().addBlock('h2');
    store().updateBlock(0, { text: 'Заголовок' });
    expect(blocks()[0].text).toBe('Заголовок');
  });

  it('removeBlock deletes by index', () => {
    store().addBlock('h2');
    store().addBlock('p');
    store().removeBlock(0);
    expect(blocks().map((b) => b.type)).toEqual(['p']);
  });

  it('moveBlock reorders and clamps at bounds', () => {
    store().addBlock('h2');
    store().addBlock('p');
    store().addBlock('sources');
    store().moveBlock(2, -1);
    expect(blocks().map((b) => b.type)).toEqual(['h2', 'sources', 'p']);
    // out of bounds is a no-op
    store().moveBlock(0, -1);
    expect(blocks().map((b) => b.type)).toEqual(['h2', 'sources', 'p']);
    store().moveBlock(2, 1);
    expect(blocks().map((b) => b.type)).toEqual(['h2', 'sources', 'p']);
  });

  it('changeBlockType swaps type and carries text over', () => {
    store().addBlock('h2');
    store().updateBlock(0, { text: 'Перенос' });
    store().changeBlockType(0, 'p');
    expect(blocks()[0].type).toBe('p');
    expect(blocks()[0].text).toBe('Перенос');
  });

  it('changeBlockType to a type without text drops text cleanly', () => {
    store().addBlock('h2');
    store().updateBlock(0, { text: 'X' });
    store().changeBlockType(0, 'list');
    expect(blocks()[0].type).toBe('list');
    expect(blocks()[0].text).toBeUndefined();
    expect(blocks()[0].items).toEqual(['']);
  });
});
