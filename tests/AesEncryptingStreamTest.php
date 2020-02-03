<?php
namespace Jsq\EncryptionStreams;

use GuzzleHttp\Psr7;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

class AesEncryptingStreamTest extends TestCase
{

    const KB = 1024;
    const MB = 1048576;

    use AesEncryptionStreamTestTrait;

    /**
     * @dataProvider cartesianJoinInputCipherMethodProvider
     *
     * @param StreamInterface $plainTextStream
     * @param string $plainText
     * @param CipherMethod $iv
     */
    public function testStreamOutputSameAsOpenSSL(
        StreamInterface $plainTextStream,
        string $plainText,
        CipherMethod $iv
    ) {
        $key = 'foo';

        $this->assertSame(
            openssl_encrypt(
                $plainText,
                $iv->getOpenSslName(),
                $key,
                OPENSSL_RAW_DATA,
                $iv->getCurrentIv()
            ),
            (string) new AesEncryptingStream(
                $plainTextStream,
                $key,
                $iv
            ),
        );
    }

    /**
     * @dataProvider cartesianJoinInputCipherMethodProvider
     *
     * @param StreamInterface $plainTextStream
     * @param string $plainText
     * @param CipherMethod $iv
     */
    public function testSupportsRewinding(
        StreamInterface $plainTextStream,
        string $plainText,
        CipherMethod $iv
    ) {
        if (!$plainTextStream->isSeekable()) {
            $this->markTestSkipped('Cannot rewind encryption streams whose plaintext is not seekable');
        } else {
            $cipherText = new AesEncryptingStream($plainTextStream, 'foo', $iv);
            $firstBytes = $cipherText->read(256 * 2 + 3);
            $cipherText->rewind();
            $this->assertSame($firstBytes, $cipherText->read(256 * 2 + 3));
        }
    }

    /**
     * @dataProvider cartesianJoinInputCipherMethodProvider
     *
     * @param StreamInterface $plainTextStream
     * @param string $plainText
     * @param CipherMethod $iv
     */
    public function testAccuratelyReportsSizeOfCipherText(
        StreamInterface $plainTextStream,
        string $plainText,
        CipherMethod $iv
    ) {
        if ($plainTextStream->getSize() === null) {
            $this->markTestSkipped('Cannot read text of ciphertext stream when plaintext stream size is unknown');
        } else {
            $cipherText = new AesEncryptingStream($plainTextStream, 'foo', $iv);
            $this->assertSame($cipherText->getSize(), strlen((string) $cipherText));
        }
    }

    /**
     * @dataProvider cipherMethodProvider
     *
     * @param CipherMethod $cipherMethod
     */
    public function testMemoryUsageRemainsConstant(CipherMethod $cipherMethod)
    {
        $memory = memory_get_usage();

        $stream = new AesDecryptingStream(
            new RandomByteStream(124 * self::MB),
            'foo',
            $cipherMethod
        );

        while (!$stream->eof()) {
            $stream->read(self::MB);
        }

        // Reading 1MB chunks should take 2MB
        $this->assertLessThanOrEqual($memory + 2 * self::MB, memory_get_usage());
    }

    public function testIsNotWritable()
    {
        $stream = new AesEncryptingStream(
            new RandomByteStream(124 * self::MB),
            'foo',
            new Cbc(random_bytes(openssl_cipher_iv_length('aes-256-cbc')))
        );

        $this->assertFalse($stream->isWritable());
    }

    /**
     * @dataProvider cipherMethodProvider
     *
     * @param CipherMethod $cipherMethod
     */
    public function testReturnsPaddedOrEmptyStringWhenSourceStreamEmpty(
        CipherMethod $cipherMethod
    ){
        $stream = new AesEncryptingStream(
            Psr7\stream_for(''),
            'foo',
            $cipherMethod
        );

        $paddingLength = $cipherMethod->requiresPadding() ? AesEncryptingStream::BLOCK_SIZE : 0;

        $this->assertSame($paddingLength, strlen($stream->read(self::MB)));
        $this->assertSame($stream->read(self::MB), '');
    }

    /**
     * @dataProvider cipherMethodProvider
     *
     * @param CipherMethod $cipherMethod
     *
     * @expectedException \LogicException
     */
    public function testDoesNotSupportSeekingFromEnd(CipherMethod $cipherMethod)
    {
        $stream = new AesEncryptingStream(Psr7\stream_for('foo'), 'foo', $cipherMethod);

        $stream->seek(1, SEEK_END);
    }

    /**
     * @dataProvider seekableCipherMethodProvider
     *
     * @param CipherMethod $cipherMethod
     */
    public function testSupportsSeekingFromCurrentPosition(
        CipherMethod $cipherMethod
    ){
        $stream = new AesEncryptingStream(
            Psr7\stream_for(random_bytes(2 * self::MB)),
            'foo',
            $cipherMethod
        );

        $lastFiveBytes = substr($stream->read(self::MB), self::MB - 5);
        $stream->seek(-5, SEEK_CUR);
        $this->assertSame($lastFiveBytes, $stream->read(5));
    }
}
