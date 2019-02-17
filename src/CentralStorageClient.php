<?php

namespace CatLab\CentralStorage\Client;

use CatLab\CentralStorage\Client\Exceptions\StorageServerException;
use CatLab\CentralStorage\Client\Interfaces\CentralStorageClient as CentralStorageClientInterface;
use CatLab\CentralStorage\Client\Models\Asset;
use Config;
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
    protected $front;

    /**
     * @var string
     */
    protected $consumerKey;

    /**
     * @var string
     */
    protected $consumerSecret;

    /**
     * @var string
     */
    protected $version;

    /**
     * @return CentralStorageClient
     */
    public static function fromConfig()
    {
        $client = new CentralStorageClient(
            Config::get('centralStorage.server'),
            Config::get('centralStorage.key'),
            Config::get('centralStorage.secret'),
            null,
            Config::get('centralStorage.version')
        );

        if (!empty(Config::get('centralStorage.front'))) {
            $client->setFrontUrl(Config::get('centralStorage.front'));
        }

        return $client;
    }

    /**
     * CentralStorageClient constructor.
     * @param null $server
     * @param null $consumerKey
     * @param null $consumerSecret
     * @param ClientInterface|null $httpClient
     * @param string $version
     */
    public function __construct(
        $server = null,
        $consumerKey = null,
        $consumerSecret = null,
        ClientInterface $httpClient = null,
        $version = '1'
    ) {
        if (!isset($httpClient)) {
            $httpClient = new GuzzleClient();
        }
        $this->httpClient = $httpClient;

        $this->server = $server;
        $this->consumerKey = $consumerKey;
        $this->consumerSecret = $consumerSecret;
        $this->version = $version;
        $this->front = null;
    }

    /**
     * Optionally set the url that will be used to load assets (by end users)
     * @param string $front
     * @return $this
     */
    public function setFrontUrl(string $front)
    {
        $this->front = $front;
        return $this;
    }

    /**
     * Return the url that will be sent to end users.
     * @return string
     */
    public function getFrontUrl()
    {
        if ($this->front !== null) {
            return $this->front;
        } else {
            return $this->server;
        }
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

        $signature = $this->getSignature($request->query(), $this->algorithm, $secret);

        $request->headers->set(self::HEADER_SIGNATURE, $signature);
        $request->headers->set(self::HEADER_KEY, $key);
    }

    /**
     * @param array $parameters
     * @param string $secret
     * @return string
     */
    public function signParameters(array $parameters, $secret = null)
    {
        $secret = $secret ?? $this->consumerSecret;

        return $this->getSignature($parameters, $this->algorithm, $secret);
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

        return $this->isValidParameters($request->query(), $fullSignature, $secret);
    }

    /**
     * @param array $parameters
     * @param $providedSignature
     * @param $secret
     * @return bool
     */
    public function isValidParameters(array $parameters, $providedSignature, $secret)
    {
        $signatureParts = explode(':', $providedSignature);
        if (count($signatureParts) != 3) {
            return false;
        }

        $algorithm = array_shift($signatureParts);
        $salt = array_shift($signatureParts);
        //$signature = array_shift($signatureParts);

        $actualSignature = $this->getSignature($parameters, $algorithm, $secret, $salt);
        if (!$actualSignature) {
            return false;
        }

        return $providedSignature === $actualSignature;
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
     * Same as getSignature, but doesn't require a request.
     * @param array $parameters
     * @param string $algorithm
     * @param string $secret
     * @param string $salt
     * @return string
     */
    protected function getSignature(array $parameters, $algorithm, $secret, $salt = null)
    {
        if (!$this->isValidAlgorithm($algorithm)) {
            return false;
        }

        // Add some salt
        if (!isset($salt)) {
            $salt = str_random(16);
        }

        $parameters['salt'] = $salt;
        $parameters['secret'] = $secret;

        // Sort on key
        ksort($parameters);

        // Turn into a string
        $base = http_build_query($parameters);

        // And... hash!
        $signature = hash($algorithm, $base);

        return $algorithm . ':' . $parameters['salt'] . ':' . $signature;
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

        $version = $this->version;
        if (!empty($version)) {
            $properties['_v'] = $version;
        }

        if (!empty($properties)) {
            $query = '?' . http_build_query($properties);
        }

        $server = $server ?? $this->getFrontUrl();
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

    /**
     * Cache a public resource on our asset server and manipulate it.
     * This will not create an Asset, but instead store a cached copy of
     * the public asset on the asset server.
     *
     * In order to avoid misuse, an authentication parameter will be added.
     * Some properties might not be available.
     * @param $publicUrl
     * @param array $properties
     * @param string $server
     * @return string
     */
    public function getPublicAssetUrl($publicUrl, array $properties = [])
    {
        $base64Url = base64_encode($publicUrl);

        $parameters = [
            'url' => $publicUrl
        ];

        // We need a fixed salt in order to not break caching.
        $salt = mb_substr(md5($publicUrl), 0, 10);

        // Sign the request
        $signature = $this->getSignature($parameters, $this->algorithm, $this->consumerSecret, $salt);

        // Return a publicly accessible endpoint
        $url = $this->getFrontUrl() . '/proxy/' . $this->consumerKey . '/' . $base64Url . '/' . $signature;

        // Add properties
        $query = '';

        $version = $this->version;
        if (!empty($version)) {
            $properties['_v'] = $version;
        }

        if (!empty($properties)) {
            $query = '?' . http_build_query($properties);
        }

        return $url . $query;
    }
}