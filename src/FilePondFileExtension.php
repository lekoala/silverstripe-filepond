<?php

namespace LeKoala\FilePond;

use SilverStripe\Assets\File;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataExtension;

/**
 * @property File $owner
 */
class FilePondFileExtension extends DataExtension
{
    /**
     * @var null|\stdClass
     */
    protected $thumbnailService = null;

    /**
     * @var array<string,string>
     */
    private static $db = [
        // This helps tracking state of files uploaded through ajax uploaders
        "IsTemporary" => "Boolean",
    ];
    /**
     * @var array<string,string>
     */
    private static $has_one = [
        // Record is already used by versioned extensions
        // ChangeSetItem already uses Object convention so we use the same
        "Object" => DataObject::class,
    ];

    /**
     * Get a list of files uploaded the given DataObject
     * It doesn't mean that the files are currently or still associated!!
     *
     * @param DataObject $record
     * @return DataList|File[]
     */
    public static function getObjectFiles(DataObject $record)
    {
        return File::get()->filter([
            'ObjectID' => $record->ID,
            'ObjectClass' => get_class($record),
        ])->exclude('IsTemporary', 1);
    }

    /**
     * Called by Upload::loadIntoFile
     * @return void
     */
    public function onAfterUpload()
    {
        if (FilePondField::config()->enable_auto_thumbnails) {
            if (class_exists('\SilverStripe\AssetAdmin\Controller\AssetAdmin')) {
                AssetAdmin::create()->generateThumbnails($this->owner);
            } elseif ($this->thumbnailService) {
                $this->thumbnailService->generateThumbnails($this->owner);
            }
        }
    }
}
