<?php

namespace Tests\Http\Psr;

use Chiron\Http\Psr\Stream;
use Chiron\Http\Psr\UploadedFile;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

/**
 * @covers \Chiron\Http\Psr\UploadedFile
 */
class UploadedFileTest extends TestCase
{
    protected $cleanup;

    protected function setUp()
    {
        $this->cleanup = [];
    }

    protected function tearDown()
    {
        foreach ($this->cleanup as $file) {
            if (is_scalar($file) && file_exists($file)) {
                unlink($file);
            }
        }
    }

    public function invalidSizes()
    {
        return [
            'null'   => [null],
            'float'  => [1.1],
            'array'  => [[1]],
            'object' => [(object) [1]],
        ];
    }

    /**
     * @dataProvider invalidSizes
     */
    public function testRaisesExceptionOnInvalidSize($size)
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('size');

        new UploadedFile(new Stream(fopen('php://temp', 'wb+')), $size, UPLOAD_ERR_OK);
    }

    public function invalidErrorStatuses()
    {
        return [
            'null'     => [null],
            'true'     => [true],
            'false'    => [false],
            'float'    => [1.1],
            'string'   => ['1'],
            'array'    => [[1]],
            'object'   => [(object) [1]],
            'negative' => [-1],
            'too-big'  => [9],
        ];
    }

    /**
     * @dataProvider invalidErrorStatuses
     */
    public function testRaisesExceptionOnInvalidErrorStatus($status)
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('status');

        new UploadedFile(new Stream(fopen('php://temp', 'wb+')), 0, $status);
    }

    public function invalidFilenamesAndMediaTypes()
    {
        return [
            'true'   => [true],
            'false'  => [false],
            'int'    => [1],
            'float'  => [1.1],
            'array'  => [['string']],
            'object' => [(object) ['string']],
        ];
    }

    /**
     * @dataProvider invalidFilenamesAndMediaTypes
     */
    public function testRaisesExceptionOnInvalidClientFilename($filename)
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('filename');

        new UploadedFile(new Stream(fopen('php://temp', 'wb+')), 0, UPLOAD_ERR_OK, $filename);
    }

    /**
     * @dataProvider invalidFilenamesAndMediaTypes
     */
    public function testRaisesExceptionOnInvalidClientMediaType($mediaType)
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('media type');

        new UploadedFile(new Stream(fopen('php://temp', 'wb+')), 0, UPLOAD_ERR_OK, 'foobar.baz', $mediaType);
    }

    public function testGetStreamReturnsOriginalStreamObject()
    {
        $resource = fopen('php://temp', 'rw+');
        $stream = new Stream($resource);
        $stream->write('');

        $upload = new UploadedFile($stream, 0, UPLOAD_ERR_OK);

        $this->assertSame($stream, $upload->getStream());
    }

    public function testGetStreamReturnsWrappedPhpStream()
    {
        $stream = fopen('php://temp', 'wb+');
        $upload = new UploadedFile(new Stream($stream), 0, UPLOAD_ERR_OK);
        $uploadStream = $upload->getStream()->detach();

        $this->assertSame($stream, $uploadStream);
    }

    public function testSuccessful()
    {
        $resource = fopen('php://temp', 'rw+');
        $stream = new Stream($resource);
        $stream->write('Foo bar!');

        $upload = new UploadedFile($stream, $stream->getSize(), UPLOAD_ERR_OK, 'filename.txt', 'text/plain');

        $this->assertEquals($stream->getSize(), $upload->getSize());
        $this->assertEquals('filename.txt', $upload->getClientFilename());
        $this->assertEquals('text/plain', $upload->getClientMediaType());

        $this->cleanup[] = $to = tempnam(sys_get_temp_dir(), 'successful');
        $upload->moveTo($to);
        $this->assertFileExists($to);
        $this->assertEquals($stream->__toString(), file_get_contents($to));
    }

    public function invalidMovePaths()
    {
        return [
            'null'   => [null],
            'true'   => [true],
            'false'  => [false],
            'int'    => [1],
            'float'  => [1.1],
            'empty'  => [''],
            'array'  => [['filename']],
            'object' => [(object) ['filename']],
        ];
    }

    private function createStream(string $content = ''): StreamInterface
    {
        $resource = fopen('php://temp', 'rw+');
        $stream = new Stream($resource);
        $stream->write($content);

        return $stream;
    }

    /**
     * @dataProvider invalidMovePaths
     */
    public function testMoveRaisesExceptionForInvalidPath($path)
    {
        $stream = $this->createStream('Foo bar!');
        $upload = new UploadedFile($stream, 0, UPLOAD_ERR_OK);

        $this->cleanup[] = $path;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path');
        $upload->moveTo($path);
    }

    public function testMoveCannotBeCalledMoreThanOnce()
    {
        $stream = $this->createStream('Foo bar!');
        $upload = new UploadedFile($stream, 0, UPLOAD_ERR_OK);

        $this->cleanup[] = $to = tempnam(sys_get_temp_dir(), 'diac');
        $upload->moveTo($to);
        $this->assertTrue(file_exists($to));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('moved');
        $upload->moveTo($to);
    }

    public function testCannotRetrieveStreamAfterMove()
    {
        $stream = $this->createStream('Foo bar!');
        $upload = new UploadedFile($stream, 0, UPLOAD_ERR_OK);

        $this->cleanup[] = $to = tempnam(sys_get_temp_dir(), 'diac');
        $upload->moveTo($to);
        $this->assertFileExists($to);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('moved');
        $upload->getStream();
    }

    public function nonOkErrorStatus()
    {
        return [
            'UPLOAD_ERR_INI_SIZE'   => [UPLOAD_ERR_INI_SIZE],
            'UPLOAD_ERR_FORM_SIZE'  => [UPLOAD_ERR_FORM_SIZE],
            'UPLOAD_ERR_PARTIAL'    => [UPLOAD_ERR_PARTIAL],
            'UPLOAD_ERR_NO_FILE'    => [UPLOAD_ERR_NO_FILE],
            'UPLOAD_ERR_NO_TMP_DIR' => [UPLOAD_ERR_NO_TMP_DIR],
            'UPLOAD_ERR_CANT_WRITE' => [UPLOAD_ERR_CANT_WRITE],
            'UPLOAD_ERR_EXTENSION'  => [UPLOAD_ERR_EXTENSION],
        ];
    }

    /**
     * @dataProvider nonOkErrorStatus
     */
    /*
    public function testConstructorDoesNotRaiseExceptionForInvalidStreamWhenErrorStatusPresent($status)
    {
        $uploadedFile = new UploadedFile('not ok', 0, $status);
        $this->assertSame($status, $uploadedFile->getError());
    }*/

    /**
     * @dataProvider nonOkErrorStatus
     */
    /*
    public function testMoveToRaisesExceptionWhenErrorStatusPresent($status)
    {
        $uploadedFile = new UploadedFile('not ok', 0, $status);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('upload error');
        $uploadedFile->moveTo(__DIR__ . '/' . uniqid());
    }*/

    /**
     * @dataProvider nonOkErrorStatus
     */
    /*
    public function testGetStreamRaisesExceptionWhenErrorStatusPresent($status)
    {
        $uploadedFile = new UploadedFile('not ok', 0, $status);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('upload error');
        $stream = $uploadedFile->getStream();
    }*/

    public function testMoveToCreatesStreamIfOnlyAFilenameWasProvided()
    {
        $this->cleanup[] = $from = tempnam(sys_get_temp_dir(), 'copy_from');
        $this->cleanup[] = $to = tempnam(sys_get_temp_dir(), 'copy_to');

        copy(__FILE__, $from);

        $uploadedFile = new UploadedFile(new Stream(fopen($from, 'r')), 100, UPLOAD_ERR_OK, basename($from), 'text/plain');
        $uploadedFile->moveTo($to);

        $this->assertFileEquals(__FILE__, $to);
    }
}
