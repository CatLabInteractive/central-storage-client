<?php

namespace CatLab\CentralStorage\Client;

use Illuminate\Support\ServiceProvider;

/**
 * Class CentralStorageServiceProvider
 * @package CatLab\CentralStorage\Client
 */
class CentralStorageServiceProvider extends ServiceProvider
{
    /**
     *
     */
    public function register()
    {
        $this->app->bind(
            Interfaces\CentralStorageClient::class,
            function () {
                return CentralStorageClient::fromConfig();
            }
        );
    }
}