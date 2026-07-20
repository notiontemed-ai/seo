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
            'structure_versions'=>$this->structureVersions(),
            'capabilities'=>['read_only'=>true,'write_actions'=>false,'internal_uniqueness'=>true,'system_manifest'=>true],
            'deployed_files'=>[
                ['logical_name'=>'seo_api','sha256'=>hash_file('sha256', __DIR__ . '/../index.php') ?: ''],
                ['logical_name'=>'article_structures','sha256'=>is_file(__DIR__ . '/../data/article_structures.json') ? (hash_file('sha256', __DIR__ . '/../data/article_structures.json') ?: '') : ''],
            ],
        ];
    }
    private function structureVersions(): array
    {
        $path = __DIR__ . '/../data/article_structures.json';
        $json = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
        $configs = is_array($json['configs'] ?? null) ? $json['configs'] : (is_array($json) ? $json : []);
        $out=[]; foreach($configs as $k=>$v){ if(is_array($v)) $out[]=['id'=>(string)($v['id'] ?? $k),'version'=>(string)($v['version'] ?? '')]; }
        return $out;
    }
}
