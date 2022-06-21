<?php

namespace Tests;

use CatLab\CentralStorage\Client\CentralStorageClient;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Class SignatureTest
 * @package Tests
 */
class SignatureTest extends TestCase
{
    private $key = 'abcdef';
    private $secret = 'bcdefhijklmn';

    public function testSignature()
    {
        $client = new CentralStorageClient();

        $request = new Request();
        $request->query->add([
            'foo' => 'wololo',
            'bar' => 'awlololo'
        ]);

        $client->sign($request, $this->key, $this->secret);

        // Validate
        $this->assertTrue($client->isValid($request, $this->key, $this->secret));

        // Change one query parameter
        $request->query->set('foo', 'wololo2');

        // Should be false
        $this->assertFalse($client->isValid($request, $this->key, $this->secret));
    }
}
