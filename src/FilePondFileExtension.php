<?php

namespace LeKoala\FilePond;

use SilverStripe\Assets\File;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataExtension;
use SilverStripe\AssetAdmin\Controller\AssetAdmin;

class FilePondFileExtension extends DataExtension
{
    private static $db = [
        // This helps tracking state of files uploaded through ajax uploaders
        "IsTemporary" => "Boolean",
    ];
    private static $has_one = [
        // Record is already used by versioned extensions
        // ChangeSetItem already uses Object convention so we use the same
        "Object" => DataObject::class,
    ];

    /**
     * Get a list of files for the given DataObject
     *
     * This is just a convenience method, not a public api
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
            $thumbs = AssetAdmin::create()->generateThumbnails($this->owner);
        }
    }
}
