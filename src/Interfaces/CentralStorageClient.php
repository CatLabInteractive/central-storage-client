<?php

namespace CatLab\CentralStorage\Client\Interfaces;

use CatLab\CentralStorage\Client\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Interface CentralStorageClient
 * @package CatLab\CentralStorage\Client\Interfaces
 */
interface CentralStorageClient
{
    /**
     * Sign a request.
     * @param Request $response
     * @param $key
     * @param $secret
     * @return void
     */
    public function sign(Request $response, $key, $secret);

    /**
     * Check if a request is valid.
     * @param Request $request
     * @param $key
     * @param $secret
     * @return bool
     */
    public function isValid(Request $request, $key, $secret);

    /**
     * Store a file
     * @param File $file
     * @param array $attributes
     * @param null $server
     * @param null $key
     * @param null $secret
     * @return mixed
     */
    public function store(
        File $file,
        $attributes = [],
        $server = null,
        $key = null,
        $secret = null
    );

    /**
     * Return the url for an asset.
     * @param Asset $asset
     * @param array $properties Query parameters that will be set to the asset fetch
     * @param null $server Set if you want to override the used central storage server
     * @return mixed
     */
    public function getAssetUrl(Asset $asset, array $properties = [], $server = null);

    /**
     * Remove the asset from the central server.
     * @param Asset $asset
     * @param array $properties
     * @param null $server
     * @param null $key
     * @param null $secret
     * @return mixed
     */
    public function deleteAsset(
        Asset $asset,
        array $properties = [],
        $server = null,
        $key = null,
        $secret = null
    );
}