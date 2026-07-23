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
        'section'=>'432','primary_query'=>'боль в спине','secondary_queries'=>"лечение спины\nреабилитация",
        'search_intent'=>'informational','short_answer'=>'Краткий ответ','region_xml_id'=>'moscow','article_template_xml_id'=>'default',
        'author_id'=>'501','medical_reviewer_id'=>'502','medical_reviewed_at'=>'2026-07-21','content_updated_at'=>'2026-07-22',
        'sources'=>['PubMed','Клинические рекомендации'],
        'related_articles'=>[
            ['element_id'=>101,'source_iblock_id'=>68,'source'=>'legacy','name'=>'Старая'],
            ['element_id'=>202,'source_iblock_id'=>81,'source'=>'new','name'=>'Новая'],
            ['element_id'=>202,'source_iblock_id'=>81,'source'=>'new','name'=>'Дубль'],
        ],
        'related_services'=>[['element_id'=>301,'iblock_id'=>70],302],
        'related_clinics'=>json_encode([['element_id'=>401,'iblock_id'=>10], ['id'=>402]], JSON_UNESCAPED_UNICODE),
        'article_type_xml_id'=>'disease','article_structure'=>'disease_default','article_structure_name'=>'Заболевание','article_structure_version'=>'v2',
        'featured_image_alt'=>'Описание изображения',
    ];
}

$payload = basePayload() + ['show_form'=>'Y','form_id'=>'240644','form_button_text'=>'Записаться на приём'];
$exporter = new BitrixArticleXmlExporter();
$doc = $exporter->export($payload);
$xml = (string)$doc->saveXML();
$xp = new DOMXPath($doc);

assertTrue($xp->query('/КоммерческаяИнформация/Каталог/Товары/Товар')->length === 1, 'minimal product path missing');
assertTrue($xp->query('//Классификатор')->length === 0, 'Классификатор must not be exported');
assertTrue($exporter->validate($doc) === [], 'validate() must report no missing nodes: ' . implode(',', $exporter->validate($doc)));
assertTrue(trim((string)$xp->evaluate('string(//Товар/ЗначениеРеквизита[Наименование="CML2_CODE"]/Значение)')) === 'test-article', 'CML2_CODE failed');
assertTrue(trim((string)$xp->evaluate('string(//Товар/Группы/Ид)')) === '432', 'Группы/Ид must carry section value');

assertTrue(propValues($xp,'847') === ['disease'], 'ARTICLE_TYPE xml_id failed');
assertTrue(propValues($xp,'848') === ['боль в спине'], 'PRIMARY_QUERY failed');
assertTrue(propValues($xp,'849') === ['лечение спины','реабилитация'], 'secondary multiple failed');
assertTrue(propValues($xp,'850') === ['informational'], 'SEARCH_INTENT xml_id failed');
assertTrue(propValues($xp,'851') === ['Краткий ответ'], 'SHORT_ANSWER failed');
assertTrue(propValues($xp,'852') === ['moscow'], 'REGION xml_id failed');
assertTrue(propValues($xp,'853') === ['501'], 'AUTHOR failed');
assertTrue(propValues($xp,'854') === ['502'], 'MEDICAL_REVIEWER failed');
assertTrue(propValues($xp,'855') === ['21.07.2026'] && propValues($xp,'856') === ['22.07.2026'], 'dates failed');
assertTrue(propValues($xp,'857') === ['PubMed','Клинические рекомендации'], 'sources multiple failed');
assertTrue(propValues($xp,'858') === ['101'], 'legacy article split failed');
assertTrue(propValues($xp,'864') === ['default'], 'ARTICLE_TEMPLATE xml_id failed');
assertTrue(propValues($xp,'865') === ['disease_default'], 'ARTICLE_STRUCTURE failed');
assertTrue(propValues($xp,'866') === ['Заболевание'], 'ARTICLE_STRUCTURE_NAME failed');
assertTrue(propValues($xp,'867') === ['v2'], 'ARTICLE_STRUCTURE_VERSION failed');
assertTrue(propValues($xp,'868') === ['202'], 'new article split failed');
assertTrue(propValues($xp,'869') === ['301','302'], 'services failed');
assertTrue(propValues($xp,'870') === ['401','402'], 'clinics failed');
assertTrue(propValues($xp,'871') === ['Описание изображения'], 'FEATURED_IMAGE_ALT failed');

assertTrue(propValues($xp,'884') === ['Y'], 'SHOW_FORM must use Y');
assertTrue(propValues($xp,'885') === ['240644'], 'FORM_ID failed');
assertTrue(propValues($xp,'886') === ['Записаться на приём'], 'FORM_BUTTON_TEXT failed');

$disabled = basePayload() + ['show_form'=>'N','form_id'=>'','form_button_text'=>''];
$docDisabled = (new BitrixArticleXmlExporter())->export($disabled);
$xpDisabled = new DOMXPath($docDisabled);
assertTrue(propValues($xpDisabled,'884') === ['N'], 'disabled form must export SHOW_FORM=N');
assertTrue(propValues($xpDisabled,'885') === [], 'FORM_ID must be absent when empty');
assertTrue(propValues($xpDisabled,'886') === [], 'FORM_BUTTON_TEXT must be absent when empty');

$oldArticle = basePayload();
$docOld = (new BitrixArticleXmlExporter())->export($oldArticle);
$xpOld = new DOMXPath($docOld);
assertTrue($xpOld->query('/КоммерческаяИнформация/Каталог/Товары/Товар')->length === 1, 'old article XML must be generated');
assertTrue(propValues($xpOld,'884') === ['N'], 'old articles without form fields must export SHOW_FORM=N');
assertTrue(trim((string)$xpOld->evaluate('string(//Товар/ЗначениеРеквизита[Наименование="CML2_CODE"]/Значение)')) === 'test-article', 'old article structure changed');

$product = $xp->query('/КоммерческаяИнформация/Каталог/Товары/Товар')->item(0);
$children = [];
foreach ($product->childNodes as $child) if ($child instanceof DOMElement) $children[] = $child->nodeName;
assertTrue($children === ['Ид','Наименование','Группы','Описание','ЗначениеРеквизита','ЗначениеРеквизита','ЗначениеРеквизита','ЗначениеРеквизита','ЗначениеРеквизита','ЗначенияСвойств'], 'unexpected Товар child order: ' . implode(',', $children));
assertTrue(!str_contains($xml, '<БитриксКод>'), 'БитриксКод must not be exported');
assertTrue($xp->query('//Группа')->length === 0, 'group descriptions must not be exported');
assertTrue($xp->query('//Товар/ЗначенияСвойств')->length === 1, 'must export exactly one ЗначенияСвойств container');

echo "exporter ok\n";
