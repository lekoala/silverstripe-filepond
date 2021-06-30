<?php

if (php_sapi_name() != "cli") {
    die("This script must run from cli");
}

$files = [];
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-polyfill/dist/filepond-polyfill.min.js";
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-plugin-file-validate-type/dist/filepond-plugin-file-validate-type.min.js";
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-plugin-file-validate-size/dist/filepond-plugin-file-validate-size.min.js";
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-plugin-image-validate-size/dist/filepond-plugin-image-validate-size.min.js";
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-plugin-file-metadata/dist/filepond-plugin-file-metadata.min.js";
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-plugin-file-poster/dist/filepond-plugin-file-poster.min.css";
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-plugin-file-poster/dist/filepond-plugin-file-poster.min.js";
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-plugin-image-exif-orientation/dist/filepond-plugin-image-exif-orientation.min.js";
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-plugin-image-preview/dist/filepond-plugin-image-preview.min.css";
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-plugin-image-preview/dist/filepond-plugin-image-preview.min.js";
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-plugin-image-transform/dist/filepond-plugin-image-transform.min.js";
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-plugin-image-resize/dist/filepond-plugin-image-resize.min.js";
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-plugin-image-crop/dist/filepond-plugin-image-crop.min.js";
$files[] = "https://cdn.jsdelivr.net/gh/pqina/filepond-plugin-file-rename/dist/filepond-plugin-file-rename.min.js";
$files[] =  "https://cdn.jsdelivr.net/gh/pqina/filepond/dist/filepond.min.css";
$files[] =  "https://cdn.jsdelivr.net/gh/pqina/filepond/dist/filepond.min.js";

$bundleCss = '';
$bundleJs = '';

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
    if (!$contents) {
        throw new Exception("Failed to download $file");
    }
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    if ($ext == "js") {
        $bundleJs .= $contents;
    } else {
        $bundleCss .= $contents;
    }

    file_put_contents($destFile, $contents);
    echo "Copied $file to $destFile\n";
}
file_put_contents(dirname($dest) . "/bundle.css", $bundleCss);
file_put_contents(dirname($dest) . "/bundle.js", $bundleJs);
echo "Created bundles\n";
