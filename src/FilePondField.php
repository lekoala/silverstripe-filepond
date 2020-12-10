<?php

namespace LeKoala\FilePond;

use Exception;
use LogicException;
use RuntimeException;
use SilverStripe\Assets\File;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Control\Director;
use SilverStripe\View\Requirements;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\ORM\DataObjectInterface;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Security\Security;

/**
 * A FilePond field
 */
class FilePondField extends AbstractUploadField
{

    /**
     * @config
     * @var array
     */
    private static $allowed_actions = [
        'upload'
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
     * @var array
     */
    protected $filePondConfig = [];

    /**
     * Set a custom config value for this field
     *
     * @link https://pqina.nl/filepond/docs/patterns/api/filepond-instance/#properties
     * @param string $k
     * @param string $v
     * @return $this
     */
    public function addFilePondConfig($k, $v)
    {
        $this->filePondConfig[$k] = $v;
        return $this;
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
        $config = [
            'name' => $name, // This will also apply to the hidden fields
            'allowMultiple' => $multiple,
            'acceptedFileTypes' => $this->getAcceptedFileTypes(),
            'maxFiles' => $this->getAllowedMaxFileNumber(),
            'maxFileSize' => $this->getMaxFileSize(),
            'server' => $this->getServerOptions(),
            'files' => $this->getExistingUploadsData(),
        ];

        // image validation
        $record = $this->getForm()->getRecord();
        if ($record) {
            $sizes = $record->config()->image_sizes;
            $name = $this->getSafeName();
            if ($sizes && isset($sizes[$name])) {
                if (isset($sizes[$name][2]) && $sizes[$name][2] == 'max') {
                    $config['imageValidateSizeMaxWidth'] = $sizes[$name][0];
                    $config['imageValidateSizeMaxHeight'] = $sizes[$name][1];
                } else {
                    $config['imageValidateSizeMinWidth'] = $sizes[$name][0];
                    $config['imageValidateSizeMinHeight'] = $sizes[$name][1];
                }
            }
        }

        $config = array_merge($config, $i18nConfig, $this->filePondConfig);

        return $config;
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
        if (!$this->getForm()) {
            throw new LogicException(
                'Field must be associated with a form to call getServerOptions(). Please use $field->setForm($form);'
            );
        }
        return [
            'process' => $this->getUploadEnabled() ? $this->getLinkParameters('upload') : null,
            'fetch' => null,
            'revert' => null,
        ];
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
            // $poster = null;
            // if ($file instanceof Image) {
            //     $w = self::config()->get('thumbnail_width');
            //     $h = self::config()->get('thumbnail_height');
            //     $poster = $file->Fill($w, $h)->getAbsoluteURL();
            // }
            $existingUploads[] = [
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
                    // poster
                    // 'metadata' => [
                    //     'poster' => $poster
                    // ]
                ],

            ];
        }
        return $existingUploads;
    }

    public static function Requirements()
    {
        // Polyfill to ensure max compatibility
        Requirements::javascript("https://unpkg.com/filepond-polyfill@1.0.4/dist/filepond-polyfill.min.js");
        // File validation plugins
        Requirements::javascript("https://unpkg.com/filepond-plugin-file-validate-type@1.2.5/dist/filepond-plugin-file-validate-type.min.js");
        Requirements::javascript("https://unpkg.com/filepond-plugin-file-validate-size@2.2.2/dist/filepond-plugin-file-validate-size.min.js");
        // Image validation plugins
        Requirements::javascript("https://unpkg.com/filepond-plugin-image-validate-size@1.2.4/dist/filepond-plugin-image-validate-size.js");
        // Poster plugins
        // Requirements::javascript("https://unpkg.com/filepond-plugin-file-metadata@1.0.2/dist/filepond-plugin-file-metadata.min.js");
        // Requirements::css("https://unpkg.com/filepond-plugin-file-poster@1.0.0/dist/filepond-plugin-file-poster.min.css");
        // Requirements::javascript("https://unpkg.com/filepond-plugin-file-poster@1.0.0/dist/filepond-plugin-file-poster.min.js");
        // Image plugins
        // Requirements::javascript("https://unpkg.com/filepond-plugin-image-exif-orientation@1.0.9/dist/filepond-plugin-image-exif-orientation.js");
        // Requirements::css("https://unpkg.com/filepond-plugin-image-preview@2.0.1/dist/filepond-plugin-image-preview.min.css");
        // Requirements::javascript("https://unpkg.com/filepond-plugin-image-preview@2.0.1/dist/filepond-plugin-image-preview.min.js");
        // Base elements
        Requirements::css("https://unpkg.com/filepond@4.23.1/dist/filepond.css");
        Requirements::javascript("https://unpkg.com/filepond@4.23.1/dist/filepond.js");
        // Our custom init
        Requirements::javascript('lekoala/silverstripe-filepond:javascript/FilePondField.js');
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
        if ($this->isDisabled() || $this->isReadonly()) {
            throw new RuntimeException("Field is disabled or readonly");
        }

        // CSRF check
        $token = $this->getForm()->getSecurityToken();
        if (!$token->checkRequest($request)) {
            throw new RuntimeException("Invalid token");
        }

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
        $tempFiles = $tempFiles->where("Created <= '" . date('Y-m-d H:i:s', $thresholdTime) . "'");

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
