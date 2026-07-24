import { describe, it, expect } from 'vitest';
import { renderArticle } from '../src/lib/htmlRenderer.js';

describe('renderArticle (JS port)', () => {
  it('renders short answer, TOC, anchors and disclaimer', () => {
    const html = renderArticle(
      [
        { type: 'short_answer', text: 'Ответ **сразу**' },
        { type: 'h2', text: 'Раздел один' },
        { type: 'p', text: 'Текст со [ссылкой](https://temed.ru).' },
        { type: 'h2', text: 'Раздел два' },
      ],
      {}
    );
    expect(html).toContain('article-short-answer');
    expect(html).toContain('<strong>сразу</strong>');
    expect(html).toContain('article-toc');
    expect(html).toContain('<h2 id="section-1">');
    expect(html).toContain('href="#section-1"');
    expect(html).toContain('<a href="https://temed.ru">ссылкой</a>');
    expect(html).toContain('article-disclaimer');
  });

  it('escapes HTML injection and rejects javascript: urls', () => {
    const html = renderArticle(
      [
        { type: 'h2', text: 'H' },
        { type: 'p', text: '<script>alert(1)</script> [x](javascript:alert(1))' },
      ],
      {}
    );
    expect(html).not.toContain('<script>');
    expect(html).not.toContain('javascript:');
  });

  it('renders FAQ with schema.org microdata', () => {
    const html = renderArticle([{ type: 'faq', items: [{ q: 'Вопрос?', a: 'Ответ.' }] }], { disclaimer: '' });
    expect(html).toContain('itemtype="https://schema.org/FAQPage"');
    expect(html).toContain('itemprop="acceptedAnswer"');
  });

  it('enriches expert_opinion via resolver and links services in diagnostics', () => {
    const html = renderArticle(
      [
        { type: 'expert_opinion', doctor_id: 501, quote: 'Мнение' },
        { type: 'diagnostics', items: [{ method: 'МРТ', what_shows: 'структуру', related_service_id: 301 }] },
      ],
      {
        disclaimer: '',
        resolve: {
          doctors: { 501: { name: 'Иванов И.И.', position: 'Невролог', photo_url: '/p.jpg', url: '/d/ivanov' } },
          services: { 301: { name: 'МРТ', url: '/service/mrt' } },
        },
      }
    );
    expect(html).toContain('Иванов И.И.');
    expect(html).toContain('<a href="/service/mrt">');
  });

  it('links stats_highlight to its source anchor', () => {
    const html = renderArticle(
      [
        { type: 'stats_highlight', value: '90%', description: 'успех', source_index: 0 },
        { type: 'sources', items: ['Источник 1'] },
      ],
      { disclaimer: '' }
    );
    expect(html).toContain('href="#source-1"');
    expect(html).toContain('id="source-1"');
  });
});
