<?php

$EM_CONF[$_EXTKEY] = [
    'title' => '[AASHRO] Medic Care TYPO3 Template',
    'description' => 'A professional TYPO3 healthcare template with appointment booking, doctor profiles, timelines, and testimonials—ideal for clinics and medical services.',
    'category' => 'templates',
    'author' => 'Team AASHRO',
    'author_email' => 'info@aashro.com',
    'author_company' => 'AASHRO Tech',
    'state' => 'stable',
    'uploadfolder' => false,
    'clearCacheOnLoad' => false,
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.0.0-13.99.99',
            'mask' => '9.0.0-9.0.9',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
