<?php
$GLOBALS['TYPO3_CONF_VARS'] = array_replace_recursive(
    $GLOBALS['TYPO3_CONF_VARS'],
    [
        'EXTENSIONS' => [
            'mask' => [
                'backend' => 'EXT:at_medicare/Resources/Private/Mask/Backend/Templates',
                'backend_layouts_folder' => '',
                'backendlayout_pids' => '0',
                'content' => 'EXT:at_medicare/Resources/Private/Mask/Frontend/Templates',
                'content_elements_folder' => '',
                'json' => 'EXT:at_medicare/Configuration/Mask/mask.json',
                'layouts' => 'EXT:at_medicare/Resources/Private/Mask/Frontend/Layouts',
                'layouts_backend' => 'EXT:at_medicare/Resources/Private/Mask/Backend/Layouts',
                'loader_identifier' => 'json',
                'override_shared_fields' => '0',
                'partials' => 'EXT:at_medicare/Resources/Private/Mask/Frontend/Partials',
                'partials_backend' => 'EXT:at_medicare/Resources/Private/Mask/Backend/Partials',
                'preview' => 'EXT:at_medicare/Resources/Public/Mask/',
            ],
        ],
    ]
);