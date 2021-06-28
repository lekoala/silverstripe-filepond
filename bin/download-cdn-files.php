<?php

$files = [];
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-polyfill/dist/filepond-polyfill.min.js";
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-plugin-file-validate-type/dist/filepond-plugin-file-validate-type.min.js";
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-plugin-file-validate-size/dist/filepond-plugin-file-validate-size.min.js";
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-plugin-image-validate-size/dist/filepond-plugin-image-validate-size.js";
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-plugin-file-metadata/dist/filepond-plugin-file-metadata.min.js";
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-plugin-file-poster/dist/filepond-plugin-file-poster.min.css";
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-plugin-file-poster/dist/filepond-plugin-file-poster.min.js";
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-plugin-image-exif-orientation/dist/filepond-plugin-image-exif-orientation.js";
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-plugin-image-preview/dist/filepond-plugin-image-preview.min.css";
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-plugin-image-preview/dist/filepond-plugin-image-preview.min.js";
$files[] =  "https://cdn.jsdelivr.net/gh/pqina/filepond/dist/filepond.css";
$files[] =  "https://cdn.jsdelivr.net/gh/pqina/filepond/dist/filepond.js";

$baseDir = dirname(__DIR__);
$dest = $baseDir . "/javascript/cdn";

foreach ($files as $file) {
    $baseFile = str_replace("https://cdn.jsdelivr.net/gh/pqina/", "", $file);
    $parts = explode("/", $baseFile);
    $pluginName = $parts[0];

    $destFolder = $dest . "/" . $pluginName . "/dist";
    if (!is_dir($destFolder)) {
        mkdir($destFolder, 0755, true);
    }
    $destFile = $destFolder . "/" . basename($baseFile);

    $contents = file_get_contents($file);
    file_put_contents($destFile, $contents);
    echo "Copied $file to $destFile\n";
}
