<?php

namespace LeKoala\FilePond\Test;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

class Test_Model extends DataObject implements TestOnly
{
    private static $db = [
        "Name" => "Varchar",
    ];
    private static $has_one = [
        "Image" => Image::class,
        "SmallImage" => Image::class,
        "File" => File::class,
    ];
    private static $image_sizes = [
        "Image" => [1080, 1080],
        "SmallImage" => [512, 512, 'max']
    ];
    private static $table_name = 'Model';
}
