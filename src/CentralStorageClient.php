<?php

namespace CatLab\CentralStorage\Client;

use CatLab\CentralStorage\Client\Exceptions\StorageServerException;
use CatLab\CentralStorage\Client\Interfaces\CentralStorageClient as CentralStorageClientInterface;
use CatLab\CentralStorage\Client\Models\Asset;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Class CentralStorageClient
 * @package CatLab\CentralStorage\Client
 */
class CentralStorageClient implements CentralStorageClientInterface
{
    const QUERY_NONCE = 'nonce';

    const HEADER_SIGNATURE = 'centralstorage-signature';
    const HEADER_KEY = 'centralstorage-key';

    /**
     * @var string
     */
    protected $algorithm = 'sha256';

    /**
     * @var ClientInterface
     */
    protected $httpClient;

    /**
     * @var string
     */
    protected $server;

    /**
     * @var string
     */
    protected $consumerKey;

    /**
     * @var string
     */
    protected $consumerSecret;

    /**
     * @return CentralStorageClient
     */
    public static function fromConfig()
    {
        return new CentralStorageClient(
            \Config::get('centralStorage.server'),
            \Config::get('centralStorage.key'),
            \Config::get('centralStorage.secret')
        );
    }

    /**
     * CentralStorageClient constructor.
     * @param null $server
     * @param null $consumerKey
     * @param null $consumerSecret
     * @param ClientInterface|null $httpClient
     */
    public function __construct(
        $server = null,
        $consumerKey = null,
        $consumerSecret = null,
        ClientInterface $httpClient = null
    ) {
        if (!isset($httpClient)) {
            $httpClient = new GuzzleClient();
        }
        $this->httpClient = $httpClient;

        $this->server = $server;
        $this->consumerKey = $consumerKey;
        $this->consumerSecret = $consumerSecret;
    }

    /**
     * Sign a request.
     * @param Request $request
     * @param $key
     * @param $secret
     * @return void
     */
    public function sign(Request $request, $key = null, $secret = null)
    {
        $key = $key ?? $this->consumerKey;
        $secret = $secret ?? $this->consumerSecret;

        // Add a nonce that we won't check but we add it anyway.
        $request->query->set(self::QUERY_NONCE, $this->getNonce());

        $signature = $this->getSignature($request, $this->algorithm);

        $request->headers->set(self::HEADER_SIGNATURE, $signature);
        $request->headers->set(self::HEADER_KEY, $key);
    }

    /**
     * Check if a request is valid.
     * @param Request $request
     * @param $key
     * @param $secret
     * @return bool
     */
    public function isValid(Request $request, $key, $secret)
    {
        $fullSignature = $request->headers->get(self::HEADER_SIGNATURE);
        if (!$fullSignature) {
            return false;
        }

        $signatureParts = explode(':', $fullSignature);
        if (count($signatureParts) != 3) {
            return false;
        }

        $algorithm = array_shift($signatureParts);
        $salt = array_shift($signatureParts);
        $signature = array_shift($signatureParts);

        $actualSignature = $this->getSignature($request, $algorithm, $salt);
        if (!$actualSignature) {
            return false;
        }

        return $fullSignature === $actualSignature;
    }

    /**
     * @param File $file
     * @param array $attributes
     * @param null $server
     * @param null $key
     * @param null $secret
     * @return Asset
     * @throws StorageServerException
     */
    public function store(
        File $file,
        $attributes = [],
        $server = null,
        $key = null,
        $secret = null
    ) {
        $url = $this->getUrl('upload', $server);

        $request = Request::create(
            $url,
            'POST',
            [
                'attributes' => $attributes
            ]
        );

        $request->headers->replace([]);
        $request->files->add([ $file ]);

        $request->file('file', $file);
        $this->sign($request, $key, $secret);

        try {
            $result = $this->send($request);
        } catch (RequestException $e) {
            throw StorageServerException::make($e);
        }

        $rawBody = $result->getBody()->getContents();
        $body = json_decode($rawBody, true);
        if (!$body) {
            throw StorageServerException::makeFromContent($rawBody);
        }

        // Only one asset expected
        foreach ($body['assets'] as $asset) {
            return $this->makeAsset($asset);
        }

        return null;
    }

    /**
     * @param Request $request
     * @param $algorithm
     * @param null $salt
     * @return string
     */
    protected function getSignature(Request $request, $algorithm, $salt = null)
    {
        if (!$this->isValidAlgorithm($algorithm)) {
            return false;
        }

        $inputs = $request->query();

        // Add some salt
        if (!isset($salt)) {
            $salt = str_random(16);
        }

        $inputs['salt'] = $salt;

        // Sort on key
        ksort($inputs);

        // Turn into a string
        $base = http_build_query($inputs);

        // And... hash!
        $signature = hash($algorithm, $base);

        return $algorithm . ':' . $inputs['salt'] . ':' . $signature;
    }

    /**
     * @param string $algorithm
     * @return bool
     */
    protected function isValidAlgorithm($algorithm)
    {
        switch ($algorithm) {
            case 'sha256':
            case 'sha384':
            case 'sha512':
                return true;

            default:
                return false;
        }
    }

    /**
     * @return false|string
     */
    protected function getNonce()
    {
        $t = microtime(true);
        $micro = sprintf("%06d",($t - floor($t)) * 1000000);
        $d = new \DateTime( date('Y-m-d H:i:s.'.$micro, $t) );

        return $d->format("Y-m-d H:i:s.u");
    }

    /**
     * @param $path
     * @param null $server
     * @return string
     */
    protected function getUrl($path, $server = null)
    {
        $server = $server ?? $this->server;
        return $server . '/api/v1/' . $path;
    }

    /**
     * @param Request $request
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function send(Request $request)
    {
        $method = $request->getMethod();
        $url = $request->getUri();

        $options = [
            'headers' => $request->headers->all(),
            'query' => $request->query->all()
        ];

        if ($request->files->count() > 0) {

            $elements = [];
            foreach ($request->input() as $k => $v) {
                if (is_scalar($v)) {
                    $elements[] = [
                        'name' => $k,
                        'contents' => $v
                    ];
                }
            }

            $counter = 0;
            foreach ($request->files as $file) {

                /** @var UploadedFile $file */
                $filename = addslashes($file->getClientOriginalName());

                if (empty($filename)) {
                    $filename = $file->getFilename();
                }

                $elements[] = [
                    'name' => 'file_' . (++ $counter),
                    'contents' => fopen($file->path(), 'r'),
                    'filename' => $file->path(),
                    'headers' => [
                        'Content-Disposition' => 'form-data; name="file_' . (++ $counter) . '"; filename="' . $filename . '"'
                    ]
                ];
            }

            $options['multipart'] = $elements;
        } elseif ($request->input) {
            $options['json'] = $request->input->all();
        }

        //dd($psr7Request)
        $response = $this->httpClient->request($method, $url, $options);

        return $response;
    }

    /**
     * @param string $key
     * @return Asset
     */
    protected function createNewAsset($key)
    {
        $asset = new Asset();
        $asset->setAssetKey($key);

        return $asset;
    }

    /**
     * @param array $data
     * @return Asset
     */
    protected function makeAsset(array $data)
    {
        $asset = $this->createNewAsset($data['key']);

        $asset->setName($data['name']);
        $asset->setType($data['type']);
        $asset->setMimeType($data['mimetype']);
        $asset->setSize($data['size']);

        if (isset($data['width']) && isset($data['height'])) {
            $asset->setDimensions($data['width'], $data['height']);
        }

        return $asset;
    }

    /**
     * Return the url for an asset.
     * @param Asset $asset
     * @param array $properties
     * @param null $server
     * @return mixed
     */
    public function getAssetUrl(Asset $asset, array $properties = [], $server = null)
    {
        $query = '';

        $version = config('centralStorage.version');
        if (!empty($version)) {
            $properties['_v'] = $version;
        }

        if (!empty($properties)) {
            $query = '?' . http_build_query($properties);
        }

        $server = $server ?? $this->server;
        return $server . '/assets/' . $asset->getAssetKey() . $query;
    }

    /**
     * Remove the asset from the central server.
     * @param Asset $asset
     * @param array $properties
     * @param null $server
     * @param null $key
     * @param null $secret
     * @return mixed
     * @throws StorageServerException
     */
    public function deleteAsset(
        Asset $asset,
        array $properties = [],
        $server = null,
        $key = null,
        $secret = null
    ) {
        $url = $this->getUrl('assets/' . $asset->getAssetKey(), $server);

        $request = Request::create(
            $url,
            'DELETE',
            [
                'attributes' => $properties
            ]
        );

        $this->sign($request, $key, $secret);

        $result = $this->send($request);

        $rawBody = $result->getBody()->getContents();
        $body = json_decode($rawBody, true);
        if (!$body) {
            throw StorageServerException::makeFromContent($rawBody);
        }

        return $body['success'];
    }
}