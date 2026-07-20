<?php

declare(strict_types=1);

require_once __DIR__ . '/../../internal/seo-editor/lib/BitrixArticleXmlExporter.php';

$payload = json_decode((string)file_get_contents(__DIR__ . '/../fixtures/bitrix-article-payload.json'), true);
$exporter = new BitrixArticleXmlExporter();
$doc = $exporter->export($payload);
$xml = $doc->saveXML();
$checks = [
    'root' => '<КоммерческаяИнформация ВерсияСхемы="2.021"',
    'active' => '<Наименование>CML2_ACTIVE</Наименование>',
    'inactive' => '<Значение>false</Значение>',
    'code' => '<Наименование>CML2_CODE</Наименование>',
    'preview_type' => '<Тип>text</Тип>',
    'detail_type' => '<Тип>html</Тип>',
    'property_847' => '<Ид>847</Ид>',
    'property_858' => '<Ид>858</Ид>',
    'utf8' => 'Короткий анонс &amp; проверка UTF-8.',
    'cdata_terminator_safe' => ']]&amp;gt;',
];
foreach ($checks as $name => $needle) {
    if (!str_contains((string)$xml, $needle)) { fwrite(STDERR, "$name missing\n"); exit(1); }
}
if (str_contains((string)$xml, '<Значение>2</Значение>')) { fwrite(STDERR, "iblock 81 relation was not filtered\n"); exit(1); }
if ($exporter->filledProperties() < 12) { fwrite(STDERR, "too few properties\n"); exit(1); }
echo "exporter ok\n";
