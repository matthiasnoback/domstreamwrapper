<?php

require_once __DIR__ . '/DOMStreamWrapper.php';

stream_wrapper_register('dom', 'DOMStreamWrapper');

stream_context_set_default(array(
    'dom' => array(
        'version' => '1.0',
        'extension' => 'xml',
    ),
));

$context = stream_context_create(array(
    'dom' => array(
        'directory' => __DIR__ . '/resources',
    ),
));

$handle = fopen('dom://versions/versions/latestRelease', 'r', false, $context);

var_dump($handle);
