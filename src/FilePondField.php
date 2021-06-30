<?php

namespace LeKoala\FilePond;

use Exception;
use LogicException;
use RuntimeException;
use SilverStripe\Assets\File;
use SilverStripe\ORM\SS_List;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Convert;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Control\Director;
use SilverStripe\Security\Security;
use SilverStripe\View\Requirements;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\ORM\DataObjectInterface;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Core\Manifest\ModuleResourceLoader;

/**
 * A FilePond field
 */
class FilePondField extends AbstractUploadField
{
    const BASE_CDN = "https://cdn.jsdelivr.net/gh/pqina";
    const IMAGE_MODE_MIN = "min";
    const IMAGE_MODE_MAX = "max";
    const IMAGE_MODE_CROP = "crop";
    const IMAGE_MODE_RESIZE = "resize";
    const IMAGE_MODE_CROP_RESIZE = "crop_resize";

    /**
     * @config
     * @var array
     */
    private static $allowed_actions = [
        'upload',
        'chunk',
        'revert',
    ];

    /**
     * @config
     * @var boolean
     */
    private static $enable_requirements = true;

    /**
     * @config
     * @var boolean
     */
    private static $enable_validation = true;

    /**
     * @config
     * @var boolean
     */
    private static $enable_poster = false;

    /**
     * @config
     * @var boolean
     */
    private static $enable_image = false;

    /**
     * @config
     * @var boolean
     */
    private static $enable_polyfill = true;

    /**
     * @config
     * @var boolean
     */
    private static $enable_ajax_init = true;

    /**
     * @config
     * @var boolean
     */
    private static $chunk_by_default = false;

    /**
     * @config
     * @var boolean
     */
    private static $enable_default_description = true;

    /**
     * @config
     * @var boolean
     */
    private static $auto_clear_temp_folder = true;

    /**
     * @config
     * @var int
     */
    private static $auto_clear_threshold = true;

    /**
     * @config
     * @var boolean
     */
    private static $use_cdn = true;

    /**
     * @config
     * @var boolean
     */
    private static $use_bundle = false;

    /**
     * @config
     * @var boolean
     */
    private static $enable_auto_thumbnails = false;

    /**
     * @config
     * @var int
     */
    private static $poster_width = 352;

    /**
     * @config
     * @var int
     */
    private static $poster_height = 264;

    /**
     * @var array
     */
    protected $filePondConfig = [];

    /**
     * @var array
     */
    protected $customServerConfig = null;

    /**
     * @var int
     */
    protected $posterHeight = null;

    /**
     * @var int
     */
    protected $posterWidth = null;

    /**
     * Create a new file field.
     *
     * @param string $name The internal field name, passed to forms.
     * @param string $title The field label.
     * @param SS_List $items Items assigned to this field
     */
    public function __construct($name, $title = null, SS_List $items = null)
    {
        parent::__construct($name, $title, $items);

        if (self::config()->chunk_by_default) {
            $this->setChunkUploads(true);
        }
    }

    /**
     * Set a custom config value for this field
     *
     * @link https://pqina.nl/filepond/docs/patterns/api/filepond-instance/#properties
     * @param string $k
     * @param string|bool|array $v
     * @return $this
     */
    public function addFilePondConfig($k, $v)
    {
        $this->filePondConfig[$k] = $v;
        return $this;
    }

    /**
     * @param string $k
     * @param mixed $default
     * @return mixed
     */
    public function getCustomConfigValue($k, $default = null)
    {
        if (isset($this->filePondConfig[$k])) {
            return $this->filePondConfig[$k];
        }
        return $default;
    }

    /**
     * Custom configuration applied to this field
     *
     * @return array
     */
    public function getCustomFilePondConfig()
    {
        return $this->filePondConfig;
    }

    /**
     * Get the value of chunkUploads
     * @return bool
     */
    public function getChunkUploads()
    {
        if (!isset($this->filePondConfig['chunkUploads'])) {
            return false;
        }
        return $this->filePondConfig['chunkUploads'];
    }

    /**
     * Get the value of customServerConfig
     * @return array
     */
    public function getCustomServerConfig()
    {
        return $this->customServerConfig;
    }

    /**
     * Set the value of customServerConfig
     *
     * @param array $customServerConfig
     * @return $this
     */
    public function setCustomServerConfig(array $customServerConfig)
    {
        $this->customServerConfig = $customServerConfig;
        return $this;
    }

    /**
     * Set the value of chunkUploads
     *
     * Note: please set max file upload first if you want
     * to see the size limit in the description
     *
     * @param bool $chunkUploads
     * @return $this
     */
    public function setChunkUploads($chunkUploads)
    {
        $this->addFilePondConfig('chunkUploads', true);
        $this->addFilePondConfig('chunkForce', true);
        $this->addFilePondConfig('chunkSize', $this->computeMaxChunkSize());
        if ($this->isDefaultMaxFileSize()) {
            $this->showDescriptionSize = false;
        }
        return $this;
    }

    /**
     * @param array $sizes
     * @return array
     */
    public function getImageSizeConfigFromArray($sizes)
    {
        $mode = null;
        if (isset($sizes[2])) {
            $mode = $sizes[2];
        }
        return $this->getImageSizeConfig($sizes[0], $sizes[1], $mode);
    }

    /**
     * @param int $width
     * @param int $height
     * @param string $mode min|max|crop|resize|crop_resize
     * @return array
     */
    public function getImageSizeConfig($width, $height, $mode = null)
    {
        if ($mode === null) {
            $mode = self::IMAGE_MODE_MIN;
        }
        $config = [];
        switch ($mode) {
            case self::IMAGE_MODE_MIN:
                $config['imageValidateSizeMinWidth'] = $width;
                $config['imageValidateSizeMinHeight'] = $height;
                break;
            case self::IMAGE_MODE_MAX:
                $config['imageValidateSizeMaxWidth'] = $width;
                $config['imageValidateSizeMaxHeight'] = $height;
                break;
            case self::IMAGE_MODE_CROP:
                // It crops only to given ratio and tries to keep the largest image
                $config['allowImageCrop'] = true;
                $config['imageCropAspectRatio'] = "{$width}:{$height}";
                break;
            case self::IMAGE_MODE_RESIZE:
                //  Cover will respect the aspect ratio and will scale to fill the target dimensions
                $config['allowImageResize'] = true;
                $config['imageResizeTargetWidth'] = $width;
                $config['imageResizeTargetHeight'] = $height;

                // Don't use these settings and keep api simple
                // $config['imageResizeMode'] = 'cover';
                // $config['imageResizeUpscale'] = true;
                break;
            case self::IMAGE_MODE_CROP_RESIZE:
                $config['allowImageResize'] = true;
                $config['imageResizeTargetWidth'] = $width;
                $config['imageResizeTargetHeight'] = $height;
                $config['allowImageCrop'] = true;
                $config['imageCropAspectRatio'] = "{$width}:{$height}";
                break;
            default:
                throw new Exception("Unsupported '$mode' mode");
        }
        return $config;
    }

    /**
     * @param int $width
     * @param int $height
     * @param string $mode min|max|crop|resize|crop_resize
     * @return $this
     */
    public function setImageSize($width, $height, $mode = null)
    {
        $config = $this->getImageSizeConfig($width, $height, $mode);
        foreach ($config as $k => $v) {
            $this->addFilePondConfig($k, $v);
        }

        // We need a custom poster size
        $this->adjustPosterSize($width, $height);

        return $config;
    }

    /**
     * @param int $width
     * @param int $height
     * @return void
     */
    protected function adjustPosterSize($width, $height)
    {
        // If the height is smaller than our default, make smaller
        if ($height < self::getDefaultPosterHeight()) {
            $this->posterHeight = $height;
            $this->posterWidth = $width;
        } else {
            // Adjust width to keep aspect ratio with our default height
            $ratio = $height / self::getDefaultPosterHeight();
            $this->posterWidth = $width / $ratio;
        }
    }

    /**
     * Return the config applied for this field
     *
     * Typically converted to json and set in a data attribute
     *
     * @return array
     */
    public function getFilePondConfig()
    {
        $name = $this->getName();
        $multiple = $this->getIsMultiUpload();

        // Multi uploads need []
        if ($multiple && strpos($name, '[]') === false) {
            $name .= '[]';
            $this->setName($name);
        }

        $i18nConfig = [
            'labelIdle' => _t('FilePondField.labelIdle', 'Drag & Drop your files or <span class="filepond--label-action"> Browse </span>'),
            'labelFileProcessing' => _t('FilePondField.labelFileProcessing', 'Uploading'),
            'labelFileProcessingComplete' => _t('FilePondField.labelFileProcessingComplete', 'Upload complete'),
            'labelFileProcessingAborted' => _t('FilePondField.labelFileProcessingAborted', 'Upload cancelled'),
            'labelTapToCancel' => _t('FilePondField.labelTapToCancel', 'tap to cancel'),
            'labelTapToRetry' => _t('FilePondField.labelTapToCancel', 'tap to retry'),
            'labelTapToUndo' => _t('FilePondField.labelTapToCancel', 'tap to undo'),
        ];

        // Base config
        $config = [
            'name' => $name, // This will also apply to the hidden fields
            'allowMultiple' => $multiple,
            'maxFiles' => $this->getAllowedMaxFileNumber(),
            'maxFileSize' => $this->getMaxFileSize(),
            'server' => $this->getServerOptions(),
            'files' => $this->getExistingUploadsData(),
        ];

        $acceptedFileTypes = $this->getAcceptedFileTypes();
        if (!empty($acceptedFileTypes)) {
            $config['acceptedFileTypes'] = array_values($acceptedFileTypes);
        }

        // image poster
        // @link https://pqina.nl/filepond/docs/api/plugins/file-poster/#usage
        if (self::config()->enable_poster) {
            $config['filePosterHeight'] = self::config()->poster_height ?? 264;
        }

        // image validation/crop based on record
        $record = $this->getForm()->getRecord();
        if ($record) {
            $sizes = $record->config()->image_sizes;
            $name = $this->getSafeName();
            if ($sizes && isset($sizes[$name])) {
                $newConfig = $this->getImageSizeConfigFromArray($sizes[$name]);
                $config = array_merge($config, $newConfig);
                $this->adjustPosterSize($sizes[$name][0], $sizes[$name][1]);
            }
        }


        // Any custom setting will override the base ones
        $config = array_merge($config, $i18nConfig, $this->filePondConfig);

        return $config;
    }

    /**
     * Compute best size for chunks based on server settings
     *
     * @return int
     */
    protected function computeMaxChunkSize()
    {
        $maxUpload = Convert::memstring2bytes(ini_get('upload_max_filesize'));
        $maxPost = Convert::memstring2bytes(ini_get('post_max_size'));

        // ~90%, allow some overhead
        return round(min($maxUpload, $maxPost) * 0.9);
    }

    /**
     * @inheritDoc
     */
    public function setValue($value, $record = null)
    {
        // Normalize values to something similar to UploadField usage
        if (is_numeric($value)) {
            $value = ['Files' => [$value]];
        } elseif (is_array($value) && empty($value['Files'])) {
            $value = ['Files' => $value];
        }
        // Track existing record data
        if ($record) {
            $name = $this->name;
            if ($record instanceof DataObject && $record->hasMethod($name)) {
                $data = $record->$name();
                // Wrap
                if ($data instanceof DataObject) {
                    $data = new ArrayList([$data]);
                }
                foreach ($data as $uploadedItem) {
                    $this->trackFileID($uploadedItem->ID);
                }
            }
        }
        return parent::setValue($value, $record);
    }

    /**
     * Configure our endpoint
     *
     * @link https://pqina.nl/filepond/docs/patterns/api/server/
     * @return array
     */
    public function getServerOptions()
    {
        if (!empty($this->customServerConfig)) {
            return $this->customServerConfig;
        }
        if (!$this->getForm()) {
            throw new LogicException(
                'Field must be associated with a form to call getServerOptions(). Please use $field->setForm($form);'
            );
        }
        $endpoint = $this->getChunkUploads() ? 'chunk' : 'upload';
        $server = [
            'process' => $this->getUploadEnabled() ? $this->getLinkParameters($endpoint) : null,
            'fetch' => null,
            'revert' => $this->getUploadEnabled() ? $this->getLinkParameters('revert') : null,
        ];
        if ($this->getUploadEnabled() && $this->getChunkUploads()) {
            $server['fetch'] =  $this->getLinkParameters($endpoint . "?fetch=");
            $server['patch'] =  $this->getLinkParameters($endpoint . "?patch=");
        }
        return $server;
    }

    /**
     * Configure the following parameters:
     *
     * url : Path to the end point
     * method : Request method to use
     * withCredentials : Toggles the XMLHttpRequest withCredentials on or off
     * headers : An object containing additional headers to send
     * timeout : Timeout for this action
     * onload : Called when server response is received, useful for getting the unique file id from the server response
     * onerror : Called when server error is received, receis the response body, useful to select the relevant error data
     *
     * @param string $action
     * @return array
     */
    protected function getLinkParameters($action)
    {
        $form = $this->getForm();
        $token = $form->getSecurityToken()->getValue();
        $record = $form->getRecord();

        $headers = [
            'X-SecurityID' => $token
        ];
        // Allow us to track the record instance
        if ($record) {
            $headers['X-RecordClassName'] = get_class($record);
            $headers['X-RecordID'] = $record->ID;
        }
        return [
            'url' => $this->Link($action),
            'headers' => $headers,
        ];
    }

    /**
     * The maximum size of a file, for instance 5MB or 750KB
     * Suitable for JS usage
     *
     * @return string
     */
    public function getMaxFileSize()
    {
        return str_replace(' ', '', File::format_size($this->getValidator()->getAllowedMaxFileSize()));
    }

    /**
     * Set initial values to FilePondField
     * See: https://pqina.nl/filepond/docs/patterns/api/filepond-object/#setting-initial-files
     *
     * @return array
     */
    public function getExistingUploadsData()
    {
        // Both Value() & dataValue() seem to return an array eg: ['Files' => [258, 259, 257]]
        $fileIDarray = $this->Value() ?: ['Files' => []];
        if (!isset($fileIDarray['Files']) || !count($fileIDarray['Files'])) {
            return [];
        }

        $existingUploads = [];
        foreach ($fileIDarray['Files'] as $fileID) {
            /* @var $file File */
            $file = File::get()->byID($fileID);
            if (!$file) {
                continue;
            }
            $existingUpload = [
                // the server file reference
                'source' => (int) $fileID,
                // set type to local to indicate an already uploaded file
                'options' => [
                    'type' => 'local',
                    // file information
                    'file' => [
                        'name' => $file->Name,
                        'size' => (int) $file->getAbsoluteSize(),
                        'type' => $file->getMimeType(),
                    ],
                ],
                'metadata' => []
            ];

            // Show poster
            // @link https://pqina.nl/filepond/docs/api/plugins/file-poster/#usage
            if (self::config()->enable_poster && $file instanceof Image && $file->ID) {
                // Size matches the one from asset admin or from or set size
                $w = self::getDefaultPosterWidth();
                if ($this->posterWidth) {
                    $w = $this->posterWidth;
                }
                $h = self::getDefaultPosterHeight();
                if ($this->posterHeight) {
                    $h = $this->posterHeight;
                }
                $resizedImage = $file->Fill($w, $h);
                if ($resizedImage) {
                    $poster = $resizedImage->getAbsoluteURL();
                    $existingUpload['options']['metadata']['poster'] = $poster;
                }
            }
            $existingUploads[] = $existingUpload;
        }
        return $existingUploads;
    }

    /**
     * @return int
     */
    public static function getDefaultPosterWidth()
    {
        return self::config()->poster_width ?? 352;
    }

    /**
     * @return int
     */
    public static function getDefaultPosterHeight()
    {
        return self::config()->poster_height ?? 264;
    }

    /**
     * Requirements are NOT versioned since filepond is regularly updated
     *
     * @return void
     */
    public static function Requirements()
    {
        $baseDir = self::BASE_CDN;
        if (!self::config()->use_cdn || self::config()->use_bundle) {
            // We need some kind of base url to serve as a starting point
            $asset = ModuleResourceLoader::resourceURL('lekoala/silverstripe-filepond:javascript/FilePondField.js');
            $baseDir = dirname($asset) . "/cdn";
        }
        $baseDir = rtrim($baseDir, '/');

        // It will load everything regardless of enabled plugins
        if (self::config()->use_bundle) {
            Requirements::css('lekoala/silverstripe-filepond:javascript/bundle.css');
            Requirements::javascript('lekoala/silverstripe-filepond:javascript/bundle.js');
        } else {
            // Polyfill to ensure max compatibility
            if (self::config()->enable_polyfill) {
                Requirements::javascript("$baseDir/filepond-polyfill/dist/filepond-polyfill.min.js");
            }

            // File/image validation plugins
            if (self::config()->enable_validation) {
                Requirements::javascript("$baseDir/filepond-plugin-file-validate-type/dist/filepond-plugin-file-validate-type.min.js");
                Requirements::javascript("$baseDir/filepond-plugin-file-validate-size/dist/filepond-plugin-file-validate-size.min.js");
                Requirements::javascript("$baseDir/filepond-plugin-image-validate-size/dist/filepond-plugin-image-validate-size.min.js");
            }

            // Poster plugins
            if (self::config()->enable_poster) {
                Requirements::javascript("$baseDir/filepond-plugin-file-metadata/dist/filepond-plugin-file-metadata.min.js");
                Requirements::css("$baseDir/filepond-plugin-file-poster/dist/filepond-plugin-file-poster.min.css");
                Requirements::javascript("$baseDir/filepond-plugin-file-poster/dist/filepond-plugin-file-poster.min.js");
            }

            // Image plugins
            if (self::config()->enable_image) {
                Requirements::javascript("$baseDir/filepond-plugin-image-exif-orientation/dist/filepond-plugin-image-exif-orientation.min.js");
                Requirements::css("$baseDir/filepond-plugin-image-preview/dist/filepond-plugin-image-preview.min.css");
                Requirements::javascript("$baseDir/filepond-plugin-image-preview/dist/filepond-plugin-image-preview.min.js");
                Requirements::javascript("$baseDir/filepond-plugin-image-transform/dist/filepond-plugin-image-transform.min.js");
                Requirements::javascript("$baseDir/filepond-plugin-image-resize/dist/filepond-plugin-image-resize.min.js");
                Requirements::javascript("$baseDir/filepond-plugin-image-crop/dist/filepond-plugin-image-crop.min.js");
            }

            // Base elements
            Requirements::css("$baseDir/filepond/dist/filepond.min.css");
            Requirements::javascript("$baseDir/filepond/dist/filepond.min.js");
        }

        // Our custom init
        Requirements::javascript('lekoala/silverstripe-filepond:javascript/FilePondField.js');

        // In the cms, init will not be triggered
        // Or you could use simpler instead
        if (self::config()->enable_ajax_init && Director::is_ajax()) {
            Requirements::javascript('lekoala/silverstripe-filepond:javascript/FilePondField-init.js?t=' . time());
        }
    }

    public function FieldHolder($properties = array())
    {
        $config = $this->getFilePondConfig();

        $this->setAttribute('data-config', json_encode($config));

        if (self::config()->enable_requirements) {
            self::Requirements();
        }

        return parent::FieldHolder($properties);
    }

    /**
     * Check the incoming request
     *
     * @param HTTPRequest $request
     * @return array
     */
    public function prepareUpload(HTTPRequest $request)
    {
        $name = $this->getName();
        $tmpFile = $request->postVar($name);
        if (!$tmpFile) {
            throw new RuntimeException("No file");
        }
        $tmpFile = $this->normalizeTempFile($tmpFile);

        // Update $tmpFile with a better name
        if ($this->renamePattern) {
            $tmpFile['name'] = $this->changeFilenameWithPattern(
                $tmpFile['name'],
                $this->renamePattern
            );
        }

        return $tmpFile;
    }

    /**
     * @param HTTPRequest $request
     * @return void
     */
    protected function securityChecks(HTTPRequest $request)
    {
        if ($this->isDisabled() || $this->isReadonly()) {
            throw new RuntimeException("Field is disabled or readonly");
        }

        // CSRF check
        $token = $this->getForm()->getSecurityToken();
        if (!$token->checkRequest($request)) {
            throw new RuntimeException("Invalid token");
        }
    }

    /**
     * @param File $file
     * @param HTTPRequest $request
     * @return void
     */
    protected function setFileDetails(File $file, HTTPRequest $request)
    {
        // Mark as temporary until properly associated with a record
        // Files will be unmarked later on by saveInto method
        $file->IsTemporary = true;

        // We can also track the record
        $RecordID = $request->getHeader('X-RecordID');
        $RecordClassName = $request->getHeader('X-RecordClassName');
        if (!$file->ObjectID) {
            $file->ObjectID = $RecordID;
        }
        if (!$file->ObjectClass) {
            $file->ObjectClass = $RecordClassName;
        }

        if ($file->isChanged()) {
            // If possible, prevent creating a version for no reason
            // @link https://docs.silverstripe.org/en/4/developer_guides/model/versioning/#writing-changes-to-a-versioned-dataobject
            if ($file->hasExtension(Versioned::class)) {
                $file->writeWithoutVersion();
            } else {
                $file->write();
            }
        }
    }

    /**
     * Creates a single file based on a form-urlencoded upload.
     *
     * 1 client uploads file my-file.jpg as multipart/form-data using a POST request
     * 2 server saves file to unique location tmp/12345/my-file.jpg
     * 3 server returns unique location id 12345 in text/plain response
     * 4 client stores unique id 12345 in a hidden input field
     * 5 client submits the FilePond parent form containing the hidden input field with the unique id
     * 6 server uses the unique id to move tmp/12345/my-file.jpg to its final location and remove the tmp/12345 folder
     *
     * Along with the file object, FilePond also sends the file metadata to the server, both these objects are given the same name.
     *
     * @param HTTPRequest $request
     * @return HTTPResponse
     */
    public function upload(HTTPRequest $request)
    {
        try {
            $this->securityChecks($request);
            $tmpFile = $this->prepareUpload($request);
        } catch (Exception $ex) {
            return $this->httpError(400, $ex->getMessage());
        }

        $file = $this->saveTemporaryFile($tmpFile, $error);

        // Handle upload errors
        if ($error) {
            $this->getUpload()->clearErrors();
            return $this->httpError(400, json_encode($error));
        }

        // File can be an AssetContainer and not a DataObject
        if ($file instanceof DataObject) {
            $this->setFileDetails($file, $request);
        }

        $this->getUpload()->clearErrors();
        $fileId = $file->ID;
        $this->trackFileID($fileId);

        if (self::config()->auto_clear_temp_folder) {
            // Set a limit of 100 because otherwise it would be really slow
            self::clearTemporaryUploads(true, 100);
        }

        // server returns unique location id 12345 in text/plain response
        $response = new HTTPResponse($fileId);
        $response->addHeader('Content-Type', 'text/plain');
        return $response;
    }

    /**
     * @link https://pqina.nl/filepond/docs/api/server/#process-chunks
     * @param HTTPRequest $request
     * @return HTTPResponse
     */
    public function chunk(HTTPRequest $request)
    {
        try {
            $this->securityChecks($request);
        } catch (Exception $ex) {
            return $this->httpError(400, $ex->getMessage());
        }

        $method = $request->httpMethod();

        // The random token is returned as a query string
        $id = $request->getVar('patch');

        // FilePond will send a POST request (without file) to start a chunked transfer,
        // expecting to receive a unique transfer id in the response body, it'll add the Upload-Length header to this request.
        if ($method == "POST") {
            // Initial post payload doesn't contain name
            // It would be better to return some kind of random token instead
            // But FilePond stores the id upon the first request :-(
            $file = new File();
            $this->setFileDetails($file, $request);
            $fileId = $file->ID;
            $this->trackFileID($fileId);
            $response = new HTTPResponse($fileId, 200);
            $response->addHeader('Content-Type', 'text/plain');
            return $response;
        }

        // location of patch files
        $filePath = TEMP_PATH . "/filepond-" . $id;

        // FilePond will send a HEAD request to determine which chunks have already been uploaded,
        // expecting the file offset of the next expected chunk in the Upload-Offset response header.
        if ($method == "HEAD") {
            $nextOffset = 0;
            while (is_file($filePath . '.patch.' . $nextOffset)) {
                $nextOffset++;
            }

            $response = new HTTPResponse($nextOffset, 200);
            $response->addHeader('Content-Type', 'text/plain');
            $response->addHeader('Upload-Offset', $nextOffset);
            return $response;
        }

        // FilePond will send a PATCH request to push a chunk to the server.
        // Each of these requests is accompanied by a Content-Type, Upload-Offset, Upload-Name, and Upload-Length header.
        if ($method != "PATCH") {
            return $this->httpError(400, "Invalid method");
        }

        // The name of the file being transferred
        $uploadName = $request->getHeader('Upload-Name');
        // The offset of the chunk being transferred (starts with 0)
        $offset = $request->getHeader('Upload-Offset');
        // The total size of the file being transferred (in bytes)
        $length = (int) $request->getHeader('Upload-Length');

        // should be numeric values, else exit
        if (!is_numeric($offset) || !is_numeric($length)) {
            return $this->httpError(400, "Invalid offset or length");
        }

        // write patch file for this request
        file_put_contents($filePath . '.patch.' . $offset, $request->getBody());

        // calculate total size of patches
        $size = 0;
        $patch = glob($filePath . '.patch.*');
        foreach ($patch as $filename) {
            $size += filesize($filename);
        }
        // if total size equals length of file we have gathered all patch files
        if ($size >= $length) {
            // create output file
            $outputFile = fopen($filePath, 'wb');
            // write patches to file
            foreach ($patch as $filename) {
                // get offset from filename
                list($dir, $offset) = explode('.patch.', $filename, 2);
                // read patch and close
                $patchFile = fopen($filename, 'rb');
                $patchContent = fread($patchFile, filesize($filename));
                fclose($patchFile);

                // apply patch
                fseek($outputFile, (int) $offset);
                fwrite($outputFile, $patchContent);
            }
            // remove patches
            foreach ($patch as $filename) {
                unlink($filename);
            }
            // done with file
            fclose($outputFile);

            // Finalize real filename

            // We need to class this as it mutates the state and set the record if any
            $relationClass = $this->getRelationAutosetClass(null);
            $realFilename = $this->getFolderName() . "/" . $uploadName;
            if ($this->renamePattern) {
                $realFilename = $this->changeFilenameWithPattern(
                    $realFilename,
                    $this->renamePattern
                );
            }

            // write output file to asset store
            $file = $this->getFileByID($id);
            if (!$file) {
                return $this->httpError(400, "File $id not found");
            }
            $file->setFromLocalFile($filePath);
            $file->setFilename($realFilename);
            $file->Title = $uploadName;
            // Set proper class
            $relationClass = File::get_class_for_file_extension(
                File::get_file_extension($realFilename)
            );
            $file->setClassName($relationClass);
            $file->write();
            // Reload file instance to get the right class
            // it is not cached so we should get a fresh record
            $file = $this->getFileByID($id);
            // since we don't go through our upload object, call extension manually
            $file->extend('onAfterUpload');
        }
        $response = new HTTPResponse('', 204);
        return $response;
    }

    /**
     * @link https://pqina.nl/filepond/docs/api/server/#revert
     * @param HTTPRequest $request
     * @return HTTPResponse
     */
    public function revert(HTTPRequest $request)
    {
        try {
            $this->securityChecks($request);
        } catch (Exception $ex) {
            return $this->httpError(400, $ex->getMessage());
        }

        $method = $request->httpMethod();

        if ($method != "DELETE") {
            return $this->httpError(400, "Invalid method");
        }

        $fileID = (int) $request->getBody();
        if (!in_array($fileID, $this->getTrackedIDs())) {
            return $this->httpError(400, "Invalid ID");
        }
        $file = File::get()->byID($fileID);
        if (!$file->IsTemporary) {
            return $this->httpError(400, "Invalid file");
        }
        if (!$file->canDelete()) {
            return $this->httpError(400, "Cannot delete file");
        }
        $file->delete();
        $response = new HTTPResponse('', 200);
        return $response;
    }

    /**
     * Clear temp folder that should not contain any file other than temporary
     *
     * @param boolean $doDelete Set to true to actually delete the files, otherwise it's just a dry-run
     * @param int $limit
     * @return File[] List of files removed
     */
    public static function clearTemporaryUploads($doDelete = false, $limit = 0)
    {
        $tempFiles = File::get()->filter('IsTemporary', true);
        if ($limit) {
            $tempFiles = $tempFiles->limit($limit);
        }

        $threshold = self::config()->auto_clear_threshold;

        // Set a default threshold if none set
        if (!$threshold) {
            if (Director::isDev()) {
                $threshold = '-10 minutes';
            } else {
                $threshold = '-1 day';
            }
        }
        if (is_int($threshold)) {
            $thresholdTime = time() - $threshold;
        } else {
            $thresholdTime = strtotime($threshold);
        }

        // Update query to avoid fetching unecessary records
        $tempFiles = $tempFiles->filter("Created:LessThan", date('Y-m-d H:i:s', $thresholdTime));

        $filesDeleted = [];
        foreach ($tempFiles as $tempFile) {
            $createdTime = strtotime($tempFile->Created);
            if ($createdTime < $thresholdTime) {
                $filesDeleted[] = $tempFile;
                if ($doDelete) {
                    if ($tempFile->hasExtension(Versioned::class)) {
                        $tempFile->deleteFromStage(Versioned::LIVE);
                        $tempFile->deleteFromStage(Versioned::DRAFT);
                    } else {
                        $tempFile->delete();
                    }
                }
            }
        }
        return $filesDeleted;
    }

    /**
     * Allows tracking uploaded ids to prevent unauthorized attachements
     *
     * @param int $fileId
     * @return void
     */
    public function trackFileID($fileId)
    {
        $session = $this->getRequest()->getSession();
        $uploadedIDs = $this->getTrackedIDs();
        if (!in_array($fileId, $uploadedIDs)) {
            $uploadedIDs[] = $fileId;
        }
        $session->set('FilePond', $uploadedIDs);
    }

    /**
     * Get all authorized tracked ids
     * @return array
     */
    public function getTrackedIDs()
    {
        $session = $this->getRequest()->getSession();
        $uploadedIDs = $session->get('FilePond');
        if ($uploadedIDs) {
            return $uploadedIDs;
        }
        return [];
    }

    public function saveInto(DataObjectInterface $record)
    {
        // Note that the list of IDs is based on the value sent by the user
        // It can be spoofed because checks are minimal (by default, canView = true and only check if isInDB)
        $IDs = $this->getItemIDs();

        $Member = Security::getCurrentUser();

        // Ensure the files saved into the DataObject have been tracked (either because already on the DataObject or uploaded by the user)
        $trackedIDs = $this->getTrackedIDs();
        foreach ($IDs as $ID) {
            if (!in_array($ID, $trackedIDs)) {
                throw new ValidationException("Invalid file ID : $ID");
            }
        }

        // Move files out of temporary folder
        foreach ($IDs as $ID) {
            $file = $this->getFileByID($ID);
            if ($file && $file->IsTemporary) {
                // The record does not have an ID which is a bad idea to attach the file to it
                if (!$record->ID) {
                    $record->write();
                }
                // Check if the member is owner
                if ($Member && $Member->ID != $file->OwnerID) {
                    throw new ValidationException("Failed to authenticate owner");
                }
                $file->IsTemporary = false;
                $file->ObjectID = $record->ID;
                $file->ObjectClass = get_class($record);
                $file->write();
            } else {
                // File was uploaded earlier, no need to do anything
            }
        }

        // Proceed
        return parent::saveInto($record);
    }

    public function Type()
    {
        return 'filepond';
    }
}
