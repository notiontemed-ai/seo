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
    'show_form'=>'Y','form_id'=>'240644','form_button_text'=>'Записаться на приём',
];
$exporter = new BitrixArticleXmlExporter();
$doc = $exporter->export($payload);
$xml = (string)$doc->saveXML();
$xp = new DOMXPath($doc);
assertTrue($xp->query('//Классификатор')->length === 1, 'Классификатор missing from document');
assertTrue($xp->query('//Классификатор/Свойства/Свойство')->length >= 1, 'Классификатор must keep at least one Свойство');
assertTrue(trim((string)$xp->evaluate('string(//Каталог/Ид)')) === '81', 'Каталог/Ид must be 81');
assertTrue(trim((string)$xp->evaluate('string(//Каталог/ИдКлассификатора)')) !== '', 'Каталог/ИдКлассификатора must be present');
assertTrue($exporter->validate($doc) === [], 'validate() must report no missing nodes: ' . implode(',', $exporter->validate($doc)));
foreach (['847','848','849','850','851','852','853','854','855','856','857','858','864','865','866','867','868','869','870','871','884','885','886'] as $id) {
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
assertTrue(propValues($xp,'884') === ['Y'], 'SHOW_FORM must use XML_ID Y');
assertTrue(propValues($xp,'885') === ['240644'], 'FORM_ID failed');
assertTrue(propValues($xp,'886') === ['Записаться на приём'], 'FORM_BUTTON_TEXT failed');
assertTrue($exporter->filledProperties() === 23, 'filledProperties must be 23, got ' . $exporter->filledProperties());

$empty = $payload;
$empty['show_form'] = 'N';$empty['form_id']='';$empty['form_button_text']='';
$empty['region'] = '';$empty['region_xml_id']='';$empty['featured_image_alt']='';$empty['related_services']=[];$empty['related_clinics']=[];$empty['content_updated_at']='';$empty['medical_reviewed_at']='';
$doc2 = (new BitrixArticleXmlExporter())->export($empty);
$xp2 = new DOMXPath($doc2);
foreach (['852','855','856','869','870','871','885','886'] as $id) assertTrue(propValues($xp2,$id) === [], 'optional property ' . $id . ' must be absent');
assertTrue(propValues($xp2,'884') === ['N'], 'disabled form must export SHOW_FORM=N');
assertTrue(propValues($xp2,'864') === ['default'], 'template fallback failed');


$fallback = $payload;
$fallback['show_form'] = '';
$fallback['form_id'] = '240644';
$docFallback = (new BitrixArticleXmlExporter())->export($fallback);
assertTrue(propValues(new DOMXPath($docFallback),'884') === ['Y'], 'empty SHOW_FORM with form_id must fallback to Y');

$invalid = $payload;
$invalid['show_form'] = 'Да';
$docInvalid = (new BitrixArticleXmlExporter())->export($invalid);
assertTrue(propValues(new DOMXPath($docInvalid),'884') === ['Y'], 'invalid SHOW_FORM must not be exported as display text');

$oldArticle = $payload;
unset($oldArticle['show_form'], $oldArticle['form_id'], $oldArticle['form_button_text']);
$docOld = (new BitrixArticleXmlExporter())->export($oldArticle);
assertTrue(propValues(new DOMXPath($docOld),'884') === ['N'], 'old articles without form fields must export SHOW_FORM=N');

// section присутствует в группах шаблона (100) — предупреждений о разделе нет
assertTrue(implode('|', $exporter->warnings()) === '' || !str_contains(implode('|', $exporter->warnings()), 'отсутствует в Классификаторе'), 'unexpected section warning for known section');

// section отсутствует в группах шаблона — должно быть предупреждение, но экспорт не блокируется
$foreign = $payload;
$foreign['section'] = '432';
$foreignExporter = new BitrixArticleXmlExporter();
$foreignDoc = $foreignExporter->export($foreign);
assertTrue(str_contains(implode('|', $foreignExporter->warnings()), 'Раздел 432 отсутствует в Классификаторе шаблона'), 'missing section must produce warning');
assertTrue($foreignExporter->validate($foreignDoc) === [], 'foreign-section document must still be valid');
assertTrue(trim((string)(new DOMXPath($foreignDoc))->evaluate('string(//Товар/Группы/Ид)')) === '432', 'Группы/Ид must carry section value');


$paths = (new BitrixArticleXmlExporter())->templatePaths();
$canonical = $paths[0];

// рассинхронизация карты PROPERTY_IDS и шаблона должна блокировать экспорт
$template = file_get_contents($canonical);
$brokenTemplate = preg_replace('#\s*<Свойство>\s*<Ид>885</Ид>.*?<Наименование>FORM_ID</Наименование>.*?</Свойство>#su', '', $template, 1);
file_put_contents($canonical, $brokenTemplate);
try {
    (new BitrixArticleXmlExporter())->export($payload);
    assertTrue(false, 'schema mismatch must throw before XML generation');
} catch (RuntimeException $e) {
    assertTrue(str_contains($e->getMessage(), 'В XML-шаблоне отсутствует свойство FORM_ID с ID 885'), 'missing FORM_ID schema problem expected');
} finally {
    file_put_contents($canonical, $template);
}

// отсутствие шаблона по всем путям — исключение (не тихий каркас)
$backup = $canonical . '.bak';
assertTrue(is_file($canonical), 'canonical template must exist for this test');
rename($canonical, $backup);
$threw = false;
try {
    (new BitrixArticleXmlExporter())->export($payload);
} catch (RuntimeException $e) {
    $threw = true;
} finally {
    rename($backup, $canonical);
}
assertTrue($threw, 'missing template must throw RuntimeException instead of building empty skeleton');

echo "exporter ok\n";
