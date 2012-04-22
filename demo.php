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

$handle = fopen("dom://versions/versions/version[@type='beta']", 'r+', false, $context);

if (false === $handle) {
    throw new \RuntimeException('Could not open DOM node');
}

echo fread($handle, 1024) . "\n"; // 1.2.3

fseek($handle, 3, SEEK_SET);

echo fread($handle, 1024) . "\n"; // .3

