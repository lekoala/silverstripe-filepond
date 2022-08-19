<?php

namespace LeKoala\FilePond\Test;

use SilverStripe\Forms\Form;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Security\Member;
use SilverStripe\Dev\SapphireTest;
use LeKoala\FilePond\FilePondField;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;
use SilverStripe\Assets\Upload_Validator;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\View\Requirements;

/**
 * Tests for FilePond module
 */
class FilePondTest extends SapphireTest
{
    /**
     * Defines the fixture file to use for this test class
     * @var string
     */
    protected static $fixture_file = 'FilePondTest.yml';

    protected static $extra_dataobjects = array(
        Test_FilePondModel::class,
    );

    public function setUp(): void
    {
        parent::setUp();
        Upload_Validator::config()->set('default_max_file_size', '5MB');
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    public function getPond()
    {
        $controller = Controller::curr();
        $controller->config()->set('url_segment', 'test_controller');
        $form = new Form($controller);

        $uploader = new FilePondField('TestUpload');
        $uploader->setRecord($this->getTestModel());
        $uploader->setForm($form);
        return $uploader;
    }

    public function getTestModel()
    {
        return $this->objFromFixture(Test_FilePondModel::class, 'demo');
    }

    public function getAdminMember()
    {
        return $this->objFromFixture(Member::class, 'admin');
    }

    public function getTempFile()
    {
        return $this->objFromFixture(File::class, 'temp');
    }

    public function getRegularFile()
    {
        return $this->objFromFixture(File::class, 'regular');
    }

    public function testGetMaxFileSize()
    {
        $pond = new FilePondField('TestUpload');
        $this->assertEquals('5MB', $pond->getMaxFileSize());
    }

    public function testDefaultDescription()
    {
        // uploaders without records don't have a description
        $pond = new FilePondField('TestUpload');
        $pond->Field(); // mock call to trigger default
        $this->assertEmpty($pond->getDescription());

        // uploaders with a record have a default description
        $pond = $this->getPond();
        $pond->Field(); // mock call to trigger default
        $this->assertNotEmpty($pond->getDescription());

        // we can still set our own
        $pond->setDescription("my description");
        $this->assertEquals("my description", $pond->getDescription());

        // custom images sizes can be recommended
        $pond = $this->getPond();
        $pond->setName("Image");
        $pond->Field(); // mock call to trigger default
        $this->assertStringContainsString("1080x1080px", $pond->getDescription());
        $this->assertStringContainsString("min", strtolower($pond->getDescription()));

        // can set a max res
        $pond = $this->getPond();
        $pond->setName("SmallImage");
        $pond->Field(); // mock call to trigger default
        $this->assertStringContainsString("512x512px", $pond->getDescription());
        $this->assertStringContainsString("max", strtolower($pond->getDescription()));

        // we don't specify extensions by default
        $pond = $this->getPond();
        $pond->Field(); // mock call to trigger default
        $this->assertStringNotContainsString("extensions", (string)$pond->getDescription());

        // image have default type jpg, jpeg, png
        $pond = $this->getPond();
        $pond->setName("Image");
        $pond->Field(); // mock call to trigger default
        $this->assertStringContainsString("extensions", $pond->getDescription());

        // but we do if we have a small list
        $pond = $this->getPond();
        $pond->setName("Image");
        $pond->setAllowedExtensions(['jpg', 'jpeg']);
        $pond->Field(); // mock call to trigger default
        $this->assertStringContainsString("jpg", $pond->getDescription());
    }

    public function testGetAcceptedFileTypes()
    {
        $pond = $this->getPond();
        $pond->setAllowedExtensions(['jpg', 'jpeg']);
        $this->assertContains('image/jpeg', $pond->getAcceptedFileTypes());
        $this->assertCount(1, $pond->getAcceptedFileTypes());
    }

    public function testGetDefaultFolderName()
    {
        $pond = $this->getPond();
        $this->assertEquals("Test_FilePondModel/TestUpload", $pond->getFolderName());
    }

    public function testRenamePattern()
    {
        $pond = $this->getPond();
        $pond->setRenamePattern("{field}_{date}.{extension}");

        $filename = 'mytestfile.jpg';
        $expected = 'TestUpload_' . date('Ymd') . '.jpg';

        $postVars = [
            $pond->getName() => [
                'name' => $filename,
                'error' => 0,
                'test' => true,
            ]
        ];
        $opts = $pond->getServerOptions();
        $request = new HTTPRequest('POST', $opts['process']['url'], [], $postVars);
        foreach ($opts['process']['headers'] as $k => $v) {
            $request->addHeader($k, $v);
        }
        $response = $pond->prepareUpload($request);

        $this->assertEquals($expected, $response['name']);
    }

    public function testClearTempFiles()
    {
        // create a temp file
        $tempFile = new File();
        $tempFile->IsTemporary = 1;
        $tempFile->write();

        $result = FilePondField::clearTemporaryUploads();
        $this->assertCount(1, $result);

        $this->assertNotEquals($tempFile->ID, $result[0]->ID, "It should not delete a recent temporary file");
        $this->assertEquals($this->getTempFile()->ID, $result[0]->ID, "It should delete an old temporary file");
    }

    public function testCustomConfig()
    {
        $pond = $this->getPond();
        $pond->addFilePondConfig('allowDrop', false);
        $this->assertArrayHasKey('allowDrop', $pond->getFilePondConfig());
    }

    public function testRequirements()
    {
        FilePondField::config()->use_cdn = true;
        FilePondField::Requirements();
        $files = array_keys(Requirements::backend()->getJavascript());
        $this->assertContains("https://cdn.jsdelivr.net/gh/pqina/filepond/dist/filepond.min.js", $files);

        Requirements::clear();
        FilePondField::config()->use_cdn = false;
        FilePondField::Requirements();
        $files = array_keys(Requirements::backend()->getJavascript());
        $this->assertNotContains("https://cdn.jsdelivr.net/gh/pqina/filepond/dist/filepond.min.js", $files);
    }

    public function testImageSizes()
    {
        $pond = $this->getPond();

        $this->assertEquals(FilePondField::DEFAULT_POSTER_WIDTH, $pond->getPosterWidth());

        // If the size is smaller that poster width, we use the size as is
        $pond->setImageSize(10, 10);
        $this->assertEquals(10, $pond->getPosterWidth());

        // If it's larger, keep ratio
        $pond->setImageSize(100, 300);
        $ratio = 300 / FilePondField::DEFAULT_POSTER_HEIGHT;
        $targetWidth = 100 / $ratio;
        $this->assertEquals(round($targetWidth), round($pond->getPosterWidth()));
    }
}
