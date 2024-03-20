<?php

namespace LeKoala\FilePond;

use Exception;
use LogicException;
use RuntimeException;
use SilverStripe\Forms\Form;
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
use SilverStripe\ORM\FieldType\DBHTMLText;

/**
 * A FilePond field
 */
class FilePondField extends AbstractUploadField
{
    const IMAGE_MODE_MIN = "min";
    const IMAGE_MODE_MAX = "max";
    const IMAGE_MODE_CROP = "crop";
    const IMAGE_MODE_RESIZE = "resize";
    const IMAGE_MODE_CROP_RESIZE = "crop_resize";
    const DEFAULT_POSTER_HEIGHT = 264;
    const DEFAULT_POSTER_WIDTH = 352;

    /**
     * @config
     * @var array<string>
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
    private static $enable_poster = false;

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
     * @var bool
     */
    private static $auto_clear_threshold = true;

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
     * @var array<string|int,mixed|null>
     */
    protected $filePondConfig = [];

    /**
     * @var array<string,mixed>|null
     */
    protected $customServerConfig = null;

    /**
     * @var ?int
     */
    protected $posterHeight = null;

    /**
     * @var ?int
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
     * @param string|bool|float|int|array<mixed> $v
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
     * @return array<mixed>
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
     * @return array<mixed>
     */
    public function getCustomServerConfig()
    {
        return $this->customServerConfig;
    }

    /**
     * Set the value of customServerConfig
     *
     * @param array<mixed> $customServerConfig
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
     * @param array<mixed> $sizes
     * @return array<mixed>
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
     * @return array<mixed>
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
     * @link https://pqina.nl/filepond/docs/api/plugins/image-crop/
     * @link https://pqina.nl/filepond/docs/api/plugins/image-resize/
     * @link https://pqina.nl/filepond/docs/api/plugins/image-validate-size/
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

        return $this;
    }

    /**
     * This is a frontend alternative to setRenamePattern
     *
     * @link https://pqina.nl/filepond/docs/api/plugins/file-rename/
     * @param string $name The name (extension is added automatically)
     * @return $this
     */
    public function setRenameFile($name)
    {
        $this->addFilePondConfig('fileRenameFunction', $name);
        return $this;
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
            //@phpstan-ignore-next-line
            $this->posterWidth = round($width / $ratio);
        }
    }

    /**
     * @return int
     */
    public function getPosterWidth()
    {
        if ($this->posterWidth) {
            return $this->posterWidth;
        }
        return self::getDefaultPosterWidth();
    }

    /**
     * @return int
     */
    public function getPosterHeight()
    {
        if ($this->posterHeight) {
            return $this->posterHeight;
        }
        return self::getDefaultPosterHeight();
    }

    /**
     * Return the config applied for this field
     *
     * Typically converted to json and set in a data attribute
     *
     * @return array<string,mixed>
     */
    public function getFilePondConfig()
    {
        $this->fixName();
        $name = $this->getName();
        $multiple = $this->getIsMultiUpload();

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
            'server' => $this->getServerOptions(),
            'files' => $this->getExistingUploadsData(),
        ];
        $maxFileSize = $this->getMaxFileSize();
        if ($maxFileSize) {
            $config['maxFileSize'] = $maxFileSize;
        }

        $acceptedFileTypes = $this->getAcceptedFileTypes();
        if (!empty($acceptedFileTypes)) {
            $config['acceptedFileTypes'] = array_values($acceptedFileTypes);
        }

        // image poster
        // @link https://pqina.nl/filepond/docs/api/plugins/file-poster/#usage
        if (self::config()->enable_poster) {
            $config['filePosterHeight'] = self::config()->poster_height ?? self::DEFAULT_POSTER_HEIGHT;
        }

        // image validation/crop based on record
        /** @var DataObject|null $record */
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
     * @return float
     */
    protected function computeMaxChunkSize()
    {
        $upload_max_filesize = ini_get('upload_max_filesize');
        $post_max_size = ini_get('post_max_size');

        $upload_max_filesize = $upload_max_filesize ? $upload_max_filesize : "2MB";
        $post_max_size = $post_max_size ? $post_max_size : "2MB";

        $maxUpload = Convert::memstring2bytes($upload_max_filesize);
        $maxPost = Convert::memstring2bytes($post_max_size);

        // ~90%, allow some overhead
        return round(min($maxUpload, $maxPost) * 0.9);
    }

    /**
     * @param array<mixed>|int|string $value
     * @param DataObject|array<string,mixed> $record
     * @return $this
     */
    public function setValue($value, $record = null)
    {
        // Normalize values to something similar to UploadField usage
        if (is_numeric($value)) {
            $value = ['Files' => [$value]];
        } elseif (is_array($value) && empty($value['Files'])) {
            // make sure we don't assign {"name":"","full_path":"","type":"","tmp_name":"","error":4,"size":0}
            // if $_FILES is not empty
            if (isset($value['tmp_name'])) {
                $value = null;
            }
            $value = ['Files' => $value];
        }
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
        //@phpstan-ignore-next-line
        return parent::setValue($value, $record);
    }

    /**
     * Get the currently used form.
     *
     * @return Form|null
     */
    public function getForm()
    {
        return $this->form;
    }

    /**
     * Configure our endpoint
     *
     * @link https://pqina.nl/filepond/docs/patterns/api/server/
     * @return array<mixed>
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
     * @return array<mixed>
     */
    protected function getLinkParameters($action)
    {
        $form = $this->getForm();
        $token = $form->getSecurityToken()->getValue();
        /** @var DataObject|null $record */
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
        $size = $this->getValidator()->getAllowedMaxFileSize();
        if (!$size) {
            return '';
        }

        // Only supports KB and MB
        if ($size < 1024 * 1024) {
            $size = round($size / 1024) . ' KB';
        } else {
            $size = round($size / (1024 * 1024)) . ' MB';
        }

        return str_replace(' ', '', $size);
    }

    /**
     * Set initial values to FilePondField
     * See: https://pqina.nl/filepond/docs/patterns/api/filepond-object/#setting-initial-files
     *
     * @return array<mixed>
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
            /** @var File|null $file */
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
                /** @var Image|null $resizedImage */
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
        return self::config()->poster_width ?? self::DEFAULT_POSTER_WIDTH;
    }

    /**
     * @return int
     */
    public static function getDefaultPosterHeight()
    {
        return self::config()->poster_height ?? self::DEFAULT_POSTER_HEIGHT;
    }

    /**
     * @return void
     */
    public static function Requirements()
    {
        // It includes css styles already
        Requirements::javascript('lekoala/silverstripe-filepond: javascript/filepond-input.min.js');
    }

    public function getAttributes()
    {
        // don't use parent as it will include data-schema that we don'tt need
        $attributes = array(
            'class' => $this->extraClass(),
            'type' => 'file',
            'multiple' => $this->getIsMultiUpload(),
            'id' => $this->ID(),
        );

        $attributes = array_merge($attributes, $this->attributes);

        $this->fixName();
        $attributes['name'] = $this->getName();

        $this->extend('updateAttributes', $attributes);

        return $attributes;
    }

    /**
     * Make sure the name is correct
     * @return void
     */
    protected function fixName()
    {
        $name = $this->getName();
        $multiple = $this->getIsMultiUpload();

        // Multi uploads need []
        if ($multiple && strpos($name, '[]') === false) {
            $name .= '[]';
            $this->setName($name);
        }
    }

    /**
     * @param array<string,mixed> $properties
     * @return DBHTMLText
     */
    public function FieldHolder($properties = array())
    {
        if (self::config()->enable_requirements) {
            self::Requirements();
        }
        return parent::FieldHolder($properties);
    }

    /**
     * @param array<mixed> $properties
     * @return DBHTMLText|string
     */
    public function Field($properties = array())
    {
        $html = parent::Field($properties);

        $config = $this->getFilePondConfig();

        // Simply wrap with custom element and set config
        $html = "<filepond-input data-config='" . json_encode($config) . "'>" . $html . '</filepond-input>';

        return $html;
    }

    /**
     * Check the incoming request
     *
     * @param HTTPRequest $request
     * @return array<mixed>
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
        $file->IsTemporary = true; //@phpstan-ignore-line

        // We can also track the record
        $RecordID = $request->getHeader('X-RecordID');
        $RecordClassName = $request->getHeader('X-RecordClassName');
        if (!$file->ObjectID) { //@phpstan-ignore-line
            $file->ObjectID = $RecordID;
        }
        if (!$file->ObjectClass) { //@phpstan-ignore-line
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
            $jsonError = json_encode($error);
            $jsonError = $jsonError ? $jsonError : json_last_error_msg();
            return $this->httpError(400, $jsonError);
        }

        // File can be an AssetContainer and not a DataObject
        if ($file instanceof DataObject) {
            $this->setFileDetails($file, $request); //@phpstan-ignore-line
        }

        $this->getUpload()->clearErrors();
        $fileId = $file->ID; //@phpstan-ignore-line
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
            $response = new HTTPResponse((string)$fileId, 200);
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

            $response = new HTTPResponse((string)$nextOffset, 200);
            $response->addHeader('Content-Type', 'text/plain');
            $response->addHeader('Upload-Offset', (string)$nextOffset);
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
        if ($patch) {
            foreach ($patch as $filename) {
                $size += filesize($filename);
            }
        }

        // check if we are above our size limit
        $maxAllowedSize = $this->getValidator()->getAllowedMaxFileSize();
        if ($maxAllowedSize && $size > $maxAllowedSize) {
            return $this->httpError(400, "File must not be larger than " . $this->getMaxFileSize());
        }

        // if total size equals length of file we have gathered all patch files
        if ($size >= $length) {
            // create output file
            $outputFile = fopen($filePath, 'wb');
            if ($patch && $outputFile) {
                // write patches to file
                foreach ($patch as $filename) {
                    // get offset from filename
                    list($dir, $offset) = explode('.patch.', $filename, 2);
                    // read patch and close
                    $patchFile = fopen($filename, 'rb');
                    $patchFileSize = filesize($filename);
                    if ($patchFile && $patchFileSize) {
                        $patchContent = fread($patchFile, $patchFileSize);
                        if ($patchContent) {
                            fclose($patchFile);

                            // apply patch
                            fseek($outputFile, (int) $offset);
                            fwrite($outputFile, $patchContent);
                        }
                    }
                }
                // remove patches
                foreach ($patch as $filename) {
                    unlink($filename);
                }
                // done with file
                fclose($outputFile);
            }

            // Finalize real filename

            // We need to class this as it mutates the state and set the record if any
            $relationClass = $this->getRelationAutosetClass(File::class);
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

            // Cleanup temp files
            $patch = glob($filePath . '.patch.*');
            if ($patch) {
                foreach ($patch as $filename) {
                    unlink($filename);
                }
            }
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
     * @param int|string $fileId
     * @return void
     */
    public function trackFileID($fileId)
    {
        $fileId = is_string($fileId) ? intval($fileId) : $fileId;
        $session = $this->getRequest()->getSession();
        $uploadedIDs = $this->getTrackedIDs();
        if (!in_array($fileId, $uploadedIDs)) {
            $uploadedIDs[] = $fileId;
        }
        $session->set('FilePond', $uploadedIDs);
    }

    /**
     * Get all authorized tracked ids
     * @return array<mixed>
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
            //@phpstan-ignore-next-line
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
                $file->ObjectID = $record->ID; //@phpstan-ignore-line
                $file->ObjectClass = get_class($record); //@phpstan-ignore-line
                $file->write();
            } else {
                // File was uploaded earlier, no need to do anything
            }
        }

        // Proceed
        parent::saveInto($record);

        return $this;
    }

    /**
     * @return string
     */
    public function Type()
    {
        return 'filepond';
    }
}
