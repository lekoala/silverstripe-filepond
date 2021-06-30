import * as FilePond from 'filepond';
import FilePondPluginFileValidateSize from 'filepond-plugin-file-validate-size';
import FilePondPluginFileValidateType from 'filepond-plugin-file-validate-type';
import FilePondPluginImageValidateSize from 'filepond-plugin-image-validate-size';
import FilePondPluginFileMetadata from 'filepond-plugin-file-metadata';
import FilePondPluginFilePoster from 'filepond-plugin-file-poster';
import FilePondPluginImageExifOrientation from 'filepond-plugin-image-exif-orientation';
import FilePondPluginImagePreview from 'filepond-plugin-image-preview';

// Setup filepond
FilePond.registerPlugin(FilePondPluginFileValidateSize);
FilePond.registerPlugin(FilePondPluginFileValidateType);
FilePond.registerPlugin(FilePondPluginImageValidateSize);
FilePond.registerPlugin(FilePondPluginFileMetadata);
FilePond.registerPlugin(FilePondPluginFilePoster);
FilePond.registerPlugin(FilePondPluginImageExifOrientation);
FilePond.registerPlugin(FilePondPluginImagePreview);
FilePond.setOptions({
    credits: false,
});

// Attach filepond to all related inputs
function attachFilePond(rootNode = document) {
    var anchors = rootNode.querySelectorAll('input[type="file"].filepond');
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

// DOMContentLoaded gets run only once, on initial pageload
// DOMNodesInserted (custom SimplerSilverstripe event) gets emitted on pageload and on any react node mount & (batched) after XHR/fetch requests
document.addEventListener("DOMNodesInserted", function (event) {
    attachFilePond(event.target);
});
