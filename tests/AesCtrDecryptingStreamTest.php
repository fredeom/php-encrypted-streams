<?php
namespace Jsq\EncryptionStreams;

use Psr\Http\Message\StreamInterface;

class AesCtrDecryptingStreamTest extends DecryptingStreamTest
{
    protected function getCipherMethod()
    {
        return 'aes-256-ctr';
    }

    protected function getStreamInstance(StreamInterface $source, $key, $iv, $keySize)
    {
        return new AesDecryptingStream($source, $key, new CtrIv($iv), $keySize);
    }
}
