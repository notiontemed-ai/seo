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

$payload = [
    'name'=>'Тестовая статья','code'=>'test-article','preview_text'=>'Короткий анонс & проверка UTF-8.','detail_html'=>'<p>Полный текст ]]></p>',
    'section'=>'100','primary_query'=>'боль в спине','secondary_queries'=>"лечение спины\nреабилитация",
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
$exporter = new BitrixArticleXmlExporter();
$doc = $exporter->export($payload);
$xml = (string)$doc->saveXML();
$xp = new DOMXPath($doc);
foreach (['847','848','849','850','851','852','853','854','855','856','857','858','864','865','866','867','868','869','870','871'] as $id) {
    assertTrue(str_contains($xml, '<Ид>' . $id . '</Ид>'), 'property ' . $id . ' missing');
}
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
assertTrue(!str_contains(implode('|', $exporter->warnings()), 'исключена'), 'iblock 81 exclusion warning remains');
assertTrue($exporter->filledProperties() === 20, 'filledProperties must be 20, got ' . $exporter->filledProperties());

$empty = $payload;
$empty['region'] = '';$empty['region_xml_id']='';$empty['featured_image_alt']='';$empty['related_services']=[];$empty['related_clinics']=[];$empty['content_updated_at']='';$empty['medical_reviewed_at']='';
$doc2 = (new BitrixArticleXmlExporter())->export($empty);
$xp2 = new DOMXPath($doc2);
foreach (['852','855','856','869','870','871'] as $id) assertTrue(propValues($xp2,$id) === [], 'optional property ' . $id . ' must be absent');
assertTrue(propValues($xp2,'864') === ['default'], 'template fallback failed');

echo "exporter ok\n";
