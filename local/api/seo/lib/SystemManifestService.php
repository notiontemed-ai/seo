<?php

declare(strict_types=1);

final class SystemManifestService
{
    public function __construct(private array $config) {}
    public function build(): array
    {
        return [
            'editor_version'=>(string)($this->config['editor_version'] ?? API_VERSION),
            'deployed_commit'=>(string)($this->config['deployed_commit'] ?? ''),
            'iblocks'=>$this->config['iblocks'] ?? [],
            'available_actions'=>function_exists('getCapabilities') ? getCapabilities()['actions'] : [],
            'article_properties'=>function_exists('getPropertyDefinitions') ? getPropertyDefinitions((int)($this->config['iblocks']['articles'] ?? 0), null) : [],
            // Конфиг структур перенесён из API в редактор (ТЗ 4.1). Значения
            // передаёт редактор; при отсутствии данных поле остаётся пустым
            // массивом, формат манифеста не меняется.
            'structure_versions'=>$this->structureVersions(),
            'capabilities'=>['read_only'=>true,'write_actions'=>false,'internal_uniqueness'=>true,'system_manifest'=>true],
            'deployed_files'=>[
                ['logical_name'=>'seo_api','sha256'=>hash_file('sha256', __DIR__ . '/../index.php') ?: ''],
            ],
        ];
    }
    private function structureVersions(): array
    {
        // Источник структур — редактор (internal/seo-editor/data). API их больше
        // не хранит; принимаем версии, если редактор передал их в конфиге.
        $versions = $this->config['structure_versions'] ?? [];
        if (!is_array($versions)) {
            return [];
        }
        $out = [];
        foreach ($versions as $k => $v) {
            if (is_array($v)) {
                $out[] = ['id' => (string)($v['id'] ?? $k), 'version' => (string)($v['version'] ?? '')];
            }
        }
        return $out;
    }
}
