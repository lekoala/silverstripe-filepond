/* global FilePond, FilePondPluginFileValidateSize, FilePondPluginFileValidateType, FilePondPluginImageValidateSize, FilePondPluginFileMetadata, FilePondPluginFilePoster, FilePondPluginImageExifOrientation, FilePondPluginImagePreview */

var filepondIsInitialized = false;
var filepondMaxTries = 5;
function initFilePond() {
    if (filepondIsInitialized) {
        return;
    }
    if (typeof FilePondPluginFileValidateSize !== "undefined") {
        FilePond.registerPlugin(FilePondPluginFileValidateSize);
    }
    if (typeof FilePondPluginFileValidateType !== "undefined") {
        FilePond.registerPlugin(FilePondPluginFileValidateType);
    }
    if (typeof FilePondPluginImageValidateSize !== "undefined") {
        FilePond.registerPlugin(FilePondPluginImageValidateSize);
    }
    if (typeof FilePondPluginFileMetadata !== "undefined") {
        FilePond.registerPlugin(FilePondPluginFileMetadata);
    }
    if (typeof FilePondPluginFilePoster !== "undefined") {
        FilePond.registerPlugin(FilePondPluginFilePoster);
    }
    if (typeof FilePondPluginImageExifOrientation !== "undefined") {
        FilePond.registerPlugin(FilePondPluginImageExifOrientation);
    }
    if (typeof FilePondPluginImagePreview !== "undefined") {
        FilePond.registerPlugin(FilePondPluginImagePreview);
    }

    FilePond.setOptions({
        credits: false,
    });

    filepondIsInitialized = true;
}
function attachFilePond() {
    // Attach filepond to all related inputs
    var anchors = document.querySelectorAll('input[type="file"].filepond');
    if (!anchors.length && filepondMaxTries > 0) {
        setTimeout(function () {
            filepondMaxTries--;
            attachFilePond();
        }, 250);
    }
    for (var i = 0; i < anchors.length; i++) {
        var el = anchors[i];
        var pond = FilePond.create(el);
        var config = JSON.parse(el.dataset.config);
        // Allow setting a custom global handler
        if (typeof config["server"] === "string") {
            config["server"] = window[config["server"]];
        }
        for (var key in config) {
            // We can set the properties directly in the instance
            // @link https://pqina.nl/filepond/docs/patterns/api/filepond-instance/#properties
            pond[key] = config[key];
        }
    }
}
document.addEventListener("DOMContentLoaded", function () {
    if (filepondIsInitialized) {
        return;
    }
    initFilePond();
    attachFilePond();
});
// document.addEventListener("DOMNodesInserted", function () {
//     initFilePond();
//     attachFilePond();
// });
