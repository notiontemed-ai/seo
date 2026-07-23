<?php

declare(strict_types=1);

require_once __DIR__ . '/../../internal/seo-editor/lib/BitrixArticleXmlExporter.php';

function assertTrue(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, $message . "\n"); exit(1); } }
function propValues(DOMXPath $xp, string $id): array {
    $nodes = $xp->query('//Товар/ЗначенияСвойств/ЗначенияСвойства[Ид="' . $id . '"]/Значение');
    $out = [];
    foreach ($nodes as $node) $out[] = $node->textContent;
    return $out;
}
function basePayload(): array {
    return [
        'name'=>'Тестовая статья','code'=>'test-article','preview_text'=>'Короткий анонс & проверка UTF-8.','detail_html'=>'<p>Полный текст ]]></p>',
        'section'=>'100','section_name'=>'Тестовый раздел','primary_query'=>'боль в спине','secondary_queries'=>"лечение спины\nреабилитация",
        'search_intent'=>'informational','short_answer'=>'Краткий ответ','region_xml_id'=>'moscow','article_template_xml_id'=>'default',
        'author_id'=>'501','medical_reviewer_id'=>'502','medical_reviewed_at'=>'2026-07-21','content_updated_at'=>'2026-07-22',
        'sources'=>['PubMed','Клинические рекомендации'],
        'related_articles'=>[
            ['element_id'=>101,'source_iblock_id'=>68,'source'=>'legacy'],
            ['element_id'=>202,'source_iblock_id'=>81,'source'=>'new'],
            ['element_id'=>202,'source_iblock_id'=>81,'source'=>'new'],
        ],
        'related_services'=>[['element_id'=>301,'iblock_id'=>70],302],
        'related_clinics'=>json_encode([['element_id'=>401,'iblock_id'=>10], ['id'=>402]], JSON_UNESCAPED_UNICODE),
        'article_type_xml_id'=>'disease','article_structure'=>'disease_default','article_structure_name'=>'Заболевание','article_structure_version'=>'v2',
        'featured_image_alt'=>'Описание изображения',
    ];
}
function propertySchema(): array {
    $ref = new ReflectionClass(BitrixArticleXmlExporter::class);
    return $ref->getReflectionConstant('PROPERTY_SCHEMA')->getValue();
}

// Тест 1. Экспорт без внешних файлов.
$exporter = new BitrixArticleXmlExporter();
$doc = $exporter->export(basePayload());
$xml = (string)$doc->saveXML();
$xp = new DOMXPath($doc);
assertTrue($xml !== '' && $doc->documentElement?->tagName === 'КоммерческаяИнформация', 'XML must be generated without external files');

// Тест 2. Полный классификатор.
foreach (['847','848','849','850','851','852','853','854','855','856','857','858','864','865','866','867','868','869','870','871','884','885','886'] as $id) {
    assertTrue($xp->query('//Классификатор/Свойства/Свойство[Ид="' . $id . '"]')->length === 1, 'classifier property ' . $id . ' missing');
}

// Тест 3. Соответствие схемы.
foreach (propertySchema() as $code => $schema) {
    $query = '//Классификатор/Свойства/Свойство[Наименование="' . $code . '"]';
    assertTrue($xp->query($query)->length === 1, 'schema property ' . $code . ' missing');
    assertTrue(trim((string)$xp->evaluate('string(' . $query . '/Ид)')) === $schema['id'], 'schema property ' . $code . ' has wrong id');
    assertTrue(trim((string)$xp->evaluate('string(' . $query . '/Наименование)')) === $code, 'schema property ' . $code . ' has wrong name');
}

// Existing relation/date behavior remains intact.
assertTrue(propValues($xp,'847') === ['disease'], 'ARTICLE_TYPE xml_id failed');
assertTrue(propValues($xp,'850') === ['informational'], 'SEARCH_INTENT xml_id failed');
assertTrue(propValues($xp,'852') === ['moscow'], 'REGION xml_id failed');
assertTrue(propValues($xp,'864') === ['default'], 'ARTICLE_TEMPLATE xml_id failed');
assertTrue(propValues($xp,'849') === ['лечение спины','реабилитация'], 'secondary multiple failed');
assertTrue(propValues($xp,'857') === ['PubMed','Клинические рекомендации'], 'sources multiple failed');
assertTrue(propValues($xp,'858') === ['101'], 'legacy article split failed');
assertTrue(propValues($xp,'868') === ['202'], 'new article split failed');
assertTrue(propValues($xp,'869') === ['301','302'], 'services failed');
assertTrue(propValues($xp,'870') === ['401','402'], 'clinics failed');
assertTrue(propValues($xp,'855') === ['21.07.2026'] && propValues($xp,'856') === ['22.07.2026'], 'dates failed');

// Тест 4. Форма включена.
$enabled = basePayload() + ['show_form'=>'Y','form_id'=>'240644','form_button_text'=>'Записаться на приём'];
$enabledDoc = (new BitrixArticleXmlExporter())->export($enabled);
$enabledXp = new DOMXPath($enabledDoc);
assertTrue(propValues($enabledXp,'884') === ['Y'], 'SHOW_FORM must be Y');
assertTrue(propValues($enabledXp,'885') === ['240644'], 'FORM_ID failed');
assertTrue(propValues($enabledXp,'886') === ['Записаться на приём'], 'FORM_BUTTON_TEXT failed');

// Тест 5. Форма выключена.
$disabled = basePayload() + ['show_form'=>'N','form_id'=>'','form_button_text'=>''];
$disabledXp = new DOMXPath((new BitrixArticleXmlExporter())->export($disabled));
assertTrue(propValues($disabledXp,'884') === ['N'], 'SHOW_FORM must be N');
assertTrue(propValues($disabledXp,'885') === [], 'FORM_ID must be absent');
assertTrue(propValues($disabledXp,'886') === [], 'FORM_BUTTON_TEXT must be absent');

// Тест 6. Старая статья без новых полей.
$oldXp = new DOMXPath((new BitrixArticleXmlExporter())->export(basePayload()));
assertTrue(propValues($oldXp,'884') === ['N'], 'old articles without form fields must export SHOW_FORM=N');
assertTrue(propValues($oldXp,'848') === ['боль в спине'], 'old article must keep other properties');
assertTrue(propValues($oldXp,'864') === ['default'], 'template fallback failed');

$fallback = basePayload() + ['show_form'=>'','form_id'=>'240644'];
assertTrue(propValues(new DOMXPath((new BitrixArticleXmlExporter())->export($fallback)),'884') === ['Y'], 'empty SHOW_FORM with form_id must fallback to Y');
$invalid = basePayload() + ['show_form'=>'Да','form_id'=>'240644'];
assertTrue(propValues(new DOMXPath((new BitrixArticleXmlExporter())->export($invalid)),'884') === ['Y'], 'invalid SHOW_FORM must normalize');

// Тест 7. Раздел.
$sectionPayload = basePayload();
$sectionPayload['section'] = '432';
$sectionPayload['section_name'] = 'Неврология';
$sectionDoc = (new BitrixArticleXmlExporter())->export($sectionPayload);
$sectionXp = new DOMXPath($sectionDoc);
assertTrue(trim((string)$sectionXp->evaluate('string(//Классификатор/Группы/Группа/Ид)')) === '432', 'classifier group id failed');
assertTrue(trim((string)$sectionXp->evaluate('string(//Классификатор/Группы/Группа/Наименование)')) === 'Неврология', 'classifier group name failed');
assertTrue(trim((string)$sectionXp->evaluate('string(//Товар/Группы/Ид)')) === '432', 'product group id failed');

// Тест 8. Валидация результата.
assertTrue((new BitrixArticleXmlExporter())->validate($sectionDoc) === [], 'validate() must report no problems');

assertTrue($exporter->filledProperties() === 21, 'filledProperties must be 21, got ' . $exporter->filledProperties());

echo "exporter ok\n";
