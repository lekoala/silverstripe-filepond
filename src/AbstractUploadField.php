<?php

namespace LeKoala\FilePond;

use LogicException;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\SS_List;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Folder;
use SilverStripe\ORM\DataObject;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\Validator;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\FileHandleField;
use SilverStripe\Control\NullHTTPRequest;
use SilverStripe\Forms\FileUploadReceiver;
use SilverStripe\ORM\FieldType\DBHTMLText;

/**
 * An abstract class that serve as a base to implement dedicated uploaders
 *
 * This follows roughly the same pattern as the main Silverstripe UploadField class
 * but does not depend on asset admin.
 *
 * Copy pasted functions that were adapted are using NEW: comments on top of
 * the lines that are changed/added
 */
abstract class AbstractUploadField extends FormField implements FileHandleField
{
    use FileUploadReceiver;
    use ImprovedUploader;

    /**
     * Schema needs to be something else than custom otherwise it fails on ajax load because
     * we don't have a proper react component
     * @var string
     */
    protected $schemaDataType = FormField::SCHEMA_DATA_TYPE_HIDDEN;

    /**
     * @var string
     */
    protected $schemaComponent;

    /**
     * Set if uploading new files is enabled.
     * If false, only existing files can be selected
     *
     * @var bool
     */
    protected $uploadEnabled = true;

    /**
     * Set if selecting existing files is enabled.
     * If false, only new files can be selected.
     *
     * @var bool
     */
    protected $attachEnabled = true;

    /**
     * The number of files allowed for this field
     *
     * @var null|int
     */
    protected $allowedMaxFileNumber = null;

    /**
     * @var string
     */
    protected $inputType = 'file';

    /**
     * @var bool|null
     */
    protected $multiUpload = null;

    /**
     * Create a new file field.
     *
     * @param string $name The internal field name, passed to forms.
     * @param string $title The field label.
     * @param SS_List $items Items assigned to this field
     */
    public function __construct($name, $title = null, SS_List $items = null)
    {
        $this->constructFileUploadReceiver();

        // NEW : Reset default size to allow our default config to work properly
        $this->getUpload()->getValidator()->allowedMaxFileSize = [];

        // When creating new files, rename on conflict
        $this->getUpload()->setReplaceFile(false);

        parent::__construct($name, $title);
        if ($items) {
            $this->setItems($items);
        }

        // NEW : Fix null request
        if ($this->request instanceof NullHTTPRequest) {
            $this->request = Controller::curr()->getRequest();
        }
    }

    /**
     * @return array<mixed>
     */
    public function getSchemaDataDefaults()
    {
        $defaults = parent::getSchemaDataDefaults();

        /** @var Form|null $form */
        $form = $this->form;

        // NEW : wrap conditionnaly to avoid errors if not linked to a form
        if ($form) {
            $uploadLink = $this->Link('upload');
            $defaults['data']['createFileEndpoint'] = [
                'url' => $uploadLink,
                'method' => 'post',
                'payloadFormat' => 'urlencoded',
            ];
        }

        $defaults['data']['maxFilesize'] = $this->getAllowedMaxFileSize() / 1024 / 1024;
        $defaults['data']['maxFiles'] = $this->getAllowedMaxFileNumber();
        $defaults['data']['multi'] = $this->getIsMultiUpload();
        $defaults['data']['parentid'] = $this->getFolderID();
        $defaults['data']['canUpload'] = $this->getUploadEnabled();
        $defaults['data']['canAttach'] = $this->getAttachEnabled();

        return $defaults;
    }


    /**
     * Handles file uploading
     *
     * @param HTTPRequest $request
     * @return HTTPResponse
     */
    abstract public function upload(HTTPRequest $request);

    /**
     * Get ID of target parent folder
     *
     * @return int
     */
    protected function getFolderID()
    {
        $folderName = $this->getFolderName();
        if (!$folderName) {
            return 0;
        }
        $folder = Folder::find_or_make($folderName);
        return $folder ? $folder->ID : 0;
    }

    /**
     * @return array<mixed>
     */
    public function getSchemaStateDefaults()
    {
        $state = parent::getSchemaStateDefaults();
        $state['data']['files'] = $this->getItemIDs();
        $state['value'] = $this->Value() ?: ['Files' => []];
        return $state;
    }

    /**
     * Check if allowed to upload more than one file
     *
     * @return bool
     */
    public function getIsMultiUpload()
    {
        if (isset($this->multiUpload)) {
            return $this->multiUpload;
        }
        // Guess from record
        /** @var DataObject|null $record */
        $record = $this->getRecord();
        $name = $this->getName();

        // Disabled for has_one components
        if ($record && DataObject::getSchema()->hasOneComponent(get_class($record), $name)) {
            return false;
        }
        return true;
    }

    /**
     * Set upload type to multiple or single
     *
     * @param bool $bool True for multiple, false for single
     * @return $this
     */
    public function setIsMultiUpload($bool)
    {
        $this->multiUpload = $bool;
        return $this;
    }

    /**
     * Gets the number of files allowed for this field
     *
     * @return null|int
     */
    public function getAllowedMaxFileNumber()
    {
        return $this->allowedMaxFileNumber;
    }

    /**
     * Returns the max allowed filesize
     *
     * @return null|int
     */
    public function getAllowedMaxFileSize()
    {
        return $this->getValidator()->getLargestAllowedMaxFileSize();
    }

    /**
     * @return boolean
     */
    public function isDefaultMaxFileSize()
    {
        // This returns null until getAllowedMaxFileSize is called
        $current = $this->getValidator()->getLargestAllowedMaxFileSize();
        return $current ? false : true;
    }

    /**
     * Sets the number of files allowed for this field
     * @param int $count
     * @return $this
     */
    public function setAllowedMaxFileNumber($count)
    {
        $this->allowedMaxFileNumber = $count;

        return $this;
    }

    /**
     * @return array<string,mixed>
     */
    public function getAttributes()
    {
        $attributes = array(
            'class' => $this->extraClass(),
            'type' => 'file',
            'multiple' => $this->getIsMultiUpload(),
            'id' => $this->ID(),
            'data-schema' => json_encode($this->getSchemaData()),
            'data-state' => json_encode($this->getSchemaState()),
        );

        $attributes = array_merge($attributes, $this->attributes);

        $this->extend('updateAttributes', $attributes);

        return $attributes;
    }

    /**
     * @return string
     */
    public function Type()
    {
        return 'file';
    }

    public function performReadonlyTransformation()
    {
        $clone = clone $this;
        $clone->setReadonly(true);
        return $clone;
    }

    public function performDisabledTransformation()
    {
        $clone = clone $this;
        $clone->setDisabled(true);
        return $clone;
    }

    /**
     * Checks if the number of files attached adheres to the $allowedMaxFileNumber defined
     *
     * @param Validator $validator
     * @return bool
     */
    public function validate($validator)
    {
        $maxFiles = $this->getAllowedMaxFileNumber();
        $count = count($this->getItems());

        if ($maxFiles < 1 || $count <= $maxFiles) {
            return true;
        }

        $validator->validationError($this->getName(), _t(
            'FilePondField.ErrorMaxFilesReached',
            'You can only upload {count} file.|You can only upload {count} files.',
            [
                'count' => $maxFiles,
            ]
        ));

        return false;
    }

    /**
     * Check if uploading files is enabled
     *
     * @return bool
     */
    public function getUploadEnabled()
    {
        return $this->uploadEnabled;
    }

    /**
     * Set if uploading files is enabled
     *
     * @param bool $uploadEnabled
     * @return $this
     */
    public function setUploadEnabled($uploadEnabled)
    {
        $this->uploadEnabled = $uploadEnabled;
        return $this;
    }

    /**
     * Check if attaching files is enabled
     *
     * @return bool
     */
    public function getAttachEnabled()
    {
        return $this->attachEnabled;
    }

    /**
     * Set if attaching files is enabled
     *
     * @param bool $attachEnabled
     * @return AbstractUploadField
     */
    public function setAttachEnabled($attachEnabled)
    {
        $this->attachEnabled = $attachEnabled;
        return $this;
    }

    /**
     * @param array<mixed> $properties
     * @return DBHTMLText
     */
    public function Field($properties = array())
    {
        /** @var DataObject|null $record */
        $record = $this->getRecord();
        if ($record) {
            $relation = $record->getRelationClass($this->name);

            // Make sure images do not accept default stuff
            if ($relation == Image::class) {
                $allowedExtensions = $this->getAllowedExtensions();
                if (in_array('zip', $allowedExtensions)) {
                    // Only allow processable file types for images by default
                    $this->setAllowedExtensions(['jpg', 'jpeg', 'png']);
                }
            }

            // Set a default description if none set
            if (!$this->description && static::config()->enable_default_description) {
                $this->setDefaultDescription($relation, $record, $this->name);
            }
        }
        return parent::Field($properties);
    }

    /**
     * Gets the upload folder name
     *
     * Replaces method from UploadReceiver to provide a more flexible default
     *
     * @return string
     */
    public function getFolderName()
    {
        /** @var bool $hasFolder */
        $hasFolder = ($this->folderName !== false);
        return $hasFolder ? $this->folderName : $this->getDefaultFolderName();
    }

    /**
     * @inheritDoc
     */
    public function Link($action = null)
    {
        /** @var Form|null $form */
        $form = $this->form;

        if (!$form) {
            throw new LogicException(
                'Field must be associated with a form to call Link(). Please use $field->setForm($form);'
            );
        }
        $name = $this->getSafeName();
        $link = Controller::join_links($form->FormAction(), 'field/' . $name, $action);
        $this->extend('updateLink', $link, $action);
        return $link;
    }
}
