<?php

declare(strict_types=1);

namespace WikiStream\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for WikiStream::fix_item_image().
 *
 * The method is protected, so we call it via ReflectionMethod.
 * It mutates the passed object in place and also returns it.
 */
final class FixItemImageTest extends TestCase
{
    private \WikiStream $ws;
    private ReflectionMethod $method;

    protected function setUp(): void
    {
        // WikiStream requires a config and a tfc; use the simplest possible
        // stubs – fix_item_image() touches neither.
        $tfc = $this->createStub(\ToolforgeCommon::class);
        $tfc->method('openDBtool')->willReturn(new \stdClass());

        $config = new \WikiStreamConfigWikiFlix();

        $this->ws     = new \WikiStream($config, $tfc);
        $this->method = new ReflectionMethod(\WikiStream::class, 'fix_item_image');
    }

    private function invoke(object &$o): object
    {
        $args = [&$o];
        return $this->method->invokeArgs($this->ws, $args);
    }

    // -----------------------------------------------------------------------
    // When $o has no `files` property the object is returned unchanged.
    // -----------------------------------------------------------------------

    public function test_no_files_property_returns_object_unchanged(): void
    {
        $o = (object) ['title' => 'Some Film', 'image' => null];

        $result = $this->invoke($o);

        $this->assertSame($o, $result);
        $this->assertNull($o->image);
        $this->assertFalse(isset($o->files));
    }

    // -----------------------------------------------------------------------
    // The `files` JSON string is decoded in place.
    // -----------------------------------------------------------------------

    public function test_files_string_is_json_decoded(): void
    {
        $o = (object) [
            'image' => 'existing.jpg',
            'files' => '[{"property":724,"key":"archive-id","is_trailer":0}]',
        ];

        $this->invoke($o);

        $this->assertIsArray($o->files);
        $this->assertCount(1, $o->files);
    }

    // -----------------------------------------------------------------------
    // When image is already set, a Commons file (property 10) does NOT
    // overwrite it.
    // -----------------------------------------------------------------------

    public function test_existing_image_is_not_overwritten_by_commons_file(): void
    {
        $fileEntry       = new \stdClass();
        $fileEntry->{'10'} = 'commons-thumbnail.jpg';

        $o        = (object) ['image' => 'poster.jpg', 'files' => null];
        $o->files = json_encode([$fileEntry]);

        $this->invoke($o);

        $this->assertSame('poster.jpg', $o->image);
    }

    // -----------------------------------------------------------------------
    // When image is null and a file entry has property key "10", that value
    // is used as the image.
    // -----------------------------------------------------------------------

    public function test_null_image_is_filled_from_commons_file_property_10(): void
    {
        $fileEntry       = new \stdClass();
        $fileEntry->{'10'} = 'commons-thumbnail.jpg';

        $o        = (object) ['image' => null, 'files' => null];
        $o->files = json_encode([$fileEntry]);

        $this->invoke($o);

        $this->assertSame('commons-thumbnail.jpg', $o->image);
    }

    // -----------------------------------------------------------------------
    // When image is null but no file entry has property key "10", image
    // stays null.
    // -----------------------------------------------------------------------

    public function test_null_image_stays_null_when_no_property_10(): void
    {
        $fileEntry           = new \stdClass();
        $fileEntry->{'724'}  = 'some-ia-id';

        $o        = (object) ['image' => null, 'files' => null];
        $o->files = json_encode([$fileEntry]);

        $this->invoke($o);

        $this->assertNull($o->image);
    }

    // -----------------------------------------------------------------------
    // Only the first file entry with property "10" is used; subsequent ones
    // do not overwrite once image is set.
    // -----------------------------------------------------------------------

    public function test_only_first_property_10_is_used_for_image(): void
    {
        $file1       = new \stdClass();
        $file1->{'10'} = 'first.jpg';

        $file2       = new \stdClass();
        $file2->{'10'} = 'second.jpg';

        $o        = (object) ['image' => null, 'files' => null];
        $o->files = json_encode([$file1, $file2]);

        $this->invoke($o);

        $this->assertSame('first.jpg', $o->image);
    }

    // -----------------------------------------------------------------------
    // A mixed list: first entry has no property "10", second does.
    // The second entry should fill the image.
    // -----------------------------------------------------------------------

    public function test_image_filled_from_second_entry_when_first_lacks_property_10(): void
    {
        $file1          = new \stdClass();
        $file1->{'724'} = 'ia-identifier';

        $file2        = new \stdClass();
        $file2->{'10'} = 'commons-video.ogv';

        $o        = (object) ['image' => null, 'files' => null];
        $o->files = json_encode([$file1, $file2]);

        $this->invoke($o);

        $this->assertSame('commons-video.ogv', $o->image);
    }

    // -----------------------------------------------------------------------
    // The method returns the same object it received (allows chaining).
    // -----------------------------------------------------------------------

    public function test_returns_same_object_reference(): void
    {
        $o = (object) ['image' => null, 'files' => '[]'];

        $result = $this->invoke($o);

        $this->assertSame($o, $result);
    }
}
