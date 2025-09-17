<?php

namespace LeKoala\FilePond;

use ReflectionClass;
use InvalidArgumentException;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Control\HTTP;
use SilverStripe\Assets\Upload;
use SilverStripe\ORM\DataObject;
use SilverStripe\Assets\FileNameFilter;

/**
 * This trait adds convenience methods and extra features to our standard uploader like:
 * - More useful default description
 * - Rename file with a pattern
 * - Default folder
 */
trait ImprovedUploader
{
    /**
     * @var string
     */
    protected $renamePattern;

    /**
     * @var boolean
     */
    protected $showDescriptionSize = true;

    /**
     * Array of accepted file types.
     * Can be mime types or wild cards. For instance ['image/*']
     * will accept all images. ['image/png', 'image/jpeg']
     * will only accepts PNGs and JPEGs.
     *
     * @return array<string>
     */
    public function getAcceptedFileTypes()
    {
        $validator = $this->getValidator();
        $extensions = $validator->getAllowedExtensions();
        $mimeTypes = HTTP::config()->uninherited('MimeTypes');

        $arr = [];
        foreach ($extensions as $ext) {
            if (isset($mimeTypes[$ext])) {
                $arr[] = $mimeTypes[$ext];
            }
        }
        $arr = array_unique($arr);
        return $arr;
    }

    /**
     * Set default description
     *
     * @param string $relation Type of relation, eg Image or File
     * @param DataObject $record A related record
     * @param string $name Relation name, eg "Logo"
     * @return string
     */
    protected function setDefaultDescription($relation, $record = null, $name = null)
    {
        $desc = '';
        if ($this->showDescriptionSize) {
            $size = File::format_size($this->getValidator()->getAllowedMaxFileSize());
            $desc .= _t('ImprovedUploader.MAXSIZE', 'Max file size: {size}', ['size' => $size]);
        }
        if ($relation == Image::class) {
            // do we have a preferred size set on the record?
            if ($record) {
                $sizes = $record->config()->image_sizes;
                if ($sizes && isset($sizes[$name])) {
                    if ($desc) {
                        $desc .= '; ';
                    }
                    // It is an array with two keys
                    $size = $sizes[$name][0] . 'x' . $sizes[$name][1];
                    if (isset($sizes[$name][2]) && $sizes[$name][2] == 'max') {
                        $desc .= _t('ImprovedUploader.MAXRESOLUTION', 'Maximum resolution: {size}px', ['size' => $size]);
                    } else {
                        $desc .= _t('ImprovedUploader.MINRESOLUTION', 'Minimum resolution: {size}px', ['size' => $size]);
                    }
                }
            }
        }

        // Only show meaningful list of extensions
        $extensions = $this->getAllowedExtensions();
        if (count($extensions) < 7) {
            if ($desc) {
                $desc .= '; ';
            }
            $desc .= _t('ImprovedUploader.ALLOWEXTENSION', 'Allowed extensions: {ext}', array('ext' => implode(',', $extensions)));
        }

        $this->description = $desc;
    }

    /**
     * @return string
     */
    protected function getDefaultFolderName()
    {
        // There is no record, use default upload folder
        if (!$this->record) {
            return Upload::config()->uploads_folder;
        }
        // The record can determine its own upload folder
        if ($this->record->hasMethod('getFolderName')) {
            return $this->record->getFolderName();
        }
        // Have a sane default for others
        $class = (new ReflectionClass($this->record))->getShortName();
        $name = $this->getSafeName();
        return $class . '/' . $name;
    }

    /**
     * Apply a pattern set with setRenamePattern
     *
     * This is more easily applied directly on the temp file array
     * where you can change the "name" key
     *
     * @param string $originalName The name of the file
     * @param string $pattern
     * @return string The filename
     */
    protected function changeFilenameWithPattern($originalName, $pattern)
    {
        // name of file, with extension
        $name = pathinfo($originalName, PATHINFO_BASENAME);
        // name of file, without extension
        $filename = pathinfo($originalName, PATHINFO_FILENAME);
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);

        $field = $this->getSafeName();

        $map = [
            '{name}' => $name,
            '{basename}' => $name,
            '{filename}' => $filename,
            '{extension}' => $extension,
            '{timestamp}' => time(),
            '{date}' => date('Ymd'),
            '{datetime}' => date('Ymd_His'),
            '{field}' => $field,
        ];
        $search = array_keys($map);
        $replace = array_values($map);
        $replacedName = str_replace($search, $replace, $pattern);

        // Ensure end result is valid
        $filter = new FileNameFilter();
        $replacedName = $filter->filter($replacedName);

        return $replacedName;
    }

    /**
     * Get the value of renamePattern
     *
     * @return string
     */
    public function getRenamePattern()
    {
        return $this->renamePattern;
    }

    /**
     * Rename pattern can use the following variables:
     * - {field}
     * - {name}
     * - {basename}
     * - {extension}
     * - {timestamp}
     * - {date}
     * - {datetime}
     *
     * A pattern should contain at least {name} or a dot
     *
     * @param string $renamePattern
     * @return $this
     */
    public function setRenamePattern($renamePattern)
    {
        // Basic check for extension
        if (strpos($renamePattern, '.') === false && strpos($renamePattern, '{name}') === false) {
            throw new InvalidArgumentException("Pattern $renamePattern should contain an extension");
        }
        $this->renamePattern = $renamePattern;
        return $this;
    }

    /**
     * Get safe name even for multi uploads
     *
     * @return string
     */
    public function getSafeName()
    {
        return str_replace('[]', '', $this->getName());
    }

    /**
     * A simple alias that makes the IDE happy
     *
     * @param int $ID
     * @return File|Image|null
     */
    protected function getFileByID($ID)
    {
        return File::get_by_id($ID);
    }

    /**
     * Convert an array of file to a single file
     *
     * This is useful for multi uploads
     *
     * @param array $tmpFile
     * @return array
     */
    protected function normalizeTempFile($tmpFile)
    {
        $newTmpFile = [];
        foreach ($tmpFile as $k => $v) {
            if (is_array($v)) {
                $v = $v[0];
            }
            $newTmpFile[$k] = $v;
        }
        return $newTmpFile;
    }
}
