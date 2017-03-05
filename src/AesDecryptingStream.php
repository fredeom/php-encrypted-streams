<?php
namespace Jsq\EncryptionStreams;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use LogicException;
use Psr\Http\Message\StreamInterface;

class AesDecryptingStream implements StreamInterface
{
    const BLOCK_SIZE = 16; // 128 bits

    use StreamDecoratorTrait;

    /**
     * @var string
     */
    private $buffer = '';

    /**
     * @var CipherMethod
     */
    private $iv;

    /**
     * @var string
     */
    private $key;

    /**
     * @var int
     */
    private $keySize;

    /**
     * @var StreamInterface
     */
    private $stream;

    /**
     * @param StreamInterface $cipherText
     * @param string $key
     * @param CipherMethod $iv
     * @param int $keySize
     */
    public function __construct(
        StreamInterface $cipherText,
        $key,
        CipherMethod $iv,
        $keySize = 256
    ) {
        $this->stream = $cipherText;
        $this->key = $key;
        $this->iv = clone $iv;
        $this->keySize = $keySize;
    }

    public function getSize()
    {
        $plainTextSize = $this->stream->getSize();

        if ($this->iv->requiresPadding()) {
            // PKCS7 padding requires that between 1 and self::BLOCK_SIZE be
            // added to the plaintext to make it an even number of blocks. The
            // plaintext is between strlen($cipherText) - self::BLOCK_SIZE and
            // strlen($cipherText) - 1
            return null;
        }

        return $plainTextSize;
    }

    public function isWritable()
    {
        return false;
    }

    public function read($length)
    {
        if ($length > strlen($this->buffer)) {
            $this->buffer .= $this->decryptBlock(
                self::BLOCK_SIZE * ceil(($length - strlen($this->buffer)) / self::BLOCK_SIZE)
            );
        }

        $data = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $data;
    }

    public function seek($offset, $whence = SEEK_SET)
    {
        if ($offset === 0 && $whence === SEEK_SET) {
            $this->buffer = '';
            $this->iv->seek(0, SEEK_SET);
            $this->stream->seek(0, SEEK_SET);
        } else {
            throw new LogicException('AES encryption streams only support being'
                . ' rewound, not arbitrary seeking.');
        }
    }

    private function decryptBlock($length)
    {
        if ($this->stream->eof()) {
            return '';
        }

        $cipherText = '';
        do {
            $cipherText .= $this->stream->read($length - strlen($cipherText));
        } while (strlen($cipherText) < $length && !$this->stream->eof());

        $options = OPENSSL_RAW_DATA;
        if (!$this->stream->eof()
            || $this->stream->getSize() !== $this->stream->tell()
        ) {
            $options |= OPENSSL_ZERO_PADDING;
        }

        $plaintext = openssl_decrypt(
            $cipherText,
            "AES-{$this->keySize}-{$this->iv->getName()}",
            $this->key,
            $options,
            $this->iv->getCurrentIv()
        );

        $this->iv->update($cipherText);

        return $plaintext;
    }
}