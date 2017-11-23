<?php

namespace CatLab\CentralStorage\Client;

use Illuminate\Support\Facades\Facade;

/**
 * Class CentralStorageClientFacade
 * @package CatLab\CentralStorage\Client
 */
class CentralStorageClientFacade extends Facade
{
    /**
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return \CatLab\CentralStorage\Client\Interfaces\CentralStorageClient::class;
    }
}