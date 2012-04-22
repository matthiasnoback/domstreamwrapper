<?php

class DOMStreamWrapperTest extends \PHPUnit_Framework_TestCase
{
    private static $context;

    public static function setUpBeforeClass()
    {
        require_once __DIR__ . '/DOMStreamWrapper.php';

        stream_wrapper_register('dom', 'DOMStreamWrapper');

        stream_context_set_default(array(
            'dom' => array(
                'version' => '1.0',
                'extension' => 'xml',
            ),
        ));

        static::$context = stream_context_create(array(
            'dom' => array(
                'directory' => __DIR__ . '/resources',
            ),
        ));
    }

    public function testOpensXmlFileAndNodeUsingXPath()
    {
        $handle = fopen("dom://versions/versions/version[@type='beta'][2]", 'r+', false, static::$context);

        $this->assertInternalType('resource', $handle);

        return $handle;
    }

    /**
     * @depends testOpensXmlFileAndNodeUsingXPath
     */
    public function testReadsNodeContents($handle)
    {
        $contents = fread($handle, 1024);

        $this->assertSame('1.2.4', $contents);
    }

    /**
     * @depends testOpensXmlFileAndNodeUsingXPath
     */
    public function testSeeksInManyWays($handle)
    {
        $this->assertSame(0, fseek($handle, 0, SEEK_SET)); // rewind
        $this->assertSame(0, fseek($handle, 3, SEEK_SET)); // to position 3

        $this->assertSame(0, fseek($handle, -1, SEEK_CUR)); // one step back
        $this->assertSame(0, fseek($handle, 0, SEEK_CUR)); // stay here
        $this->assertSame(0, fseek($handle, 1, SEEK_CUR)); // take one step further

        $this->assertSame(-1, fseek($handle, -1, SEEK_SET)); // cannot seek before the stream
        $this->assertSame(-1, fseek($handle, 10, SEEK_SET)); // cannot seek beyond the stream

        $this->assertSame(-1, fseek($handle, -5, SEEK_CUR)); // cannot seek before the stream
        $this->assertSame(-1, fseek($handle, 5, SEEK_CUR)); // cannot seek beyond the stream

        $this->assertSame(-1, fseek($handle, 5, SEEK_END)); // cannot seek beyond the stream
        $this->assertSame(-1, fseek($handle, -10, SEEK_END)); // cannot seek before the stream
    }

    public function testCreatesNodeIfNotExists()
    {
        $handle = fopen("dom://versions/versions/version/newNode", 'w+', false, static::$context);

        $this->assertInternalType('resource', $handle);
    }

    public function testTryToCreateNodeForExoticXpath()
    {
        $this->setExpectedException('PHPUnit_Framework_Error_Warning');

        fopen("dom://versions/versions/version/newNode[@type='exotic']", 'w+', false, static::$context);
    }
}
