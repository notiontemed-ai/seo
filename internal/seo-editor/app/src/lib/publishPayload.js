// Сборка payload create_or_update_draft из состояния статьи.
// Полный набор свойств инфоблока 81 (этап 8.1) + SEO-мета элемента.

// Значения блока sources синхронизируются в свойство SOURCES (857).
export function collectSources(blocks) {
  return (blocks || [])
    .filter((b) => b && b.type === 'sources')
    .flatMap((b) => (Array.isArray(b.items) ? b.items : []))
    .map((s) => String(s).trim())
    .filter(Boolean);
}

export function buildProperties(article, structures) {
  const structure = (structures || []).find((s) => s.id === article.structure_id);
  const props = {};
  const setIf = (k, v) => {
    if (v !== '' && v != null && v !== 0 && !(Array.isArray(v) && v.length === 0)) props[k] = v;
  };
  // ARTICLE_TYPE (847) удалён из редактора (ТЗ 7): свойство в Bitrix не
  // заполняется, экспортёр допускает пустое значение.
  setIf('PRIMARY_QUERY', article.primary_query);
  setIf('SECONDARY_QUERIES', article.secondary_queries);
  setIf('SEARCH_INTENT', article.search_intent);
  setIf('SHORT_ANSWER', article.short_answer);
  setIf('REGION', article.region);
  setIf('AUTHOR', article.author_id);
  setIf('MEDICAL_REVIEWER', article.medical_reviewer_id);
  setIf('MEDICAL_REVIEWED_AT', article.medical_reviewed_at);
  setIf('CONTENT_UPDATED_AT', article.content_updated_at);
  setIf('SOURCES', collectSources(article.blocks));
  setIf('RELATED_ARTICLES', article.related_articles);
  setIf('RELATED_ARTICLES_V2', article.related_articles_v2);
  setIf('RELATED_SERVICES', article.related_services);
  setIf('RELATED_CLINICS', article.related_clinics);
  setIf('FEATURED_IMAGE_ALT', article.featured_image_alt);
  if (structure) {
    setIf('ARTICLE_STRUCTURE', structure.id);
    setIf('ARTICLE_STRUCTURE_NAME', structure.name);
    setIf('ARTICLE_STRUCTURE_VERSION', structure.version || structure.v || '');
  }
  setIf('SHOW_FORM', article.show_form);
  setIf('FORM_ID', article.form_id);
  setIf('FORM_BUTTON_TEXT', article.form_button_text);
  return props;
}

// Полный payload записи черновика (ACTIVE=N ставит сервер).
export function buildDraftPayload(article, structures) {
  const seo = {};
  if ((article.seo_title || '').trim()) seo.title = article.seo_title.trim();
  if ((article.meta_description || '').trim()) seo.description = article.meta_description.trim();
  return {
    code: article.code,
    name: article.name,
    preview_text: article.preview_text,
    section_id: article.section_id,
    article_content: { schema_version: '2.0', blocks: article.blocks },
    properties: buildProperties(article, structures),
    ...(Object.keys(seo).length ? { seo } : {}),
  };
}
