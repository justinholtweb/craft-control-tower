<?php

namespace justinholtweb\controltower\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class ControlTowerCpAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];

        $this->css = [
            'css/control-tower.css',
        ];

        $this->js = [
            'js/control-tower.js',
        ];

        parent::init();
    }
}
