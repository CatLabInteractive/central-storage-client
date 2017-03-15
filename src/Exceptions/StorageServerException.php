<?php

namespace CatLab\CentralStorage\Client\Exceptions;

use GuzzleHttp\Exception\RequestException;

/**
 * Class ServerException
 * @package CatLab\CentralStorage\Exceptions
 */
class StorageServerException extends CentralStorageException
{
    /**
     * @param RequestException $e
     * @return StorageServerException
     */
    public static function make(RequestException $e)
    {
        $ex = new self('Central Storage Server Exception: ' . $e->getMessage());
        if ($e->hasResponse()) {
            $ex->response = $e->getResponse();
        }

        return $ex;
    }

    /**
     * @param $body
     * @return StorageServerException
     */
    public static function makeFromContent($body)
    {
        $ex = new self('Central Storage returned invalid content (no json)');
        $ex->response = $body;

        return $ex;
    }

    /**
     * @var string
     */
    protected $response;

    /**
     * @return string
     */
    public function getResponse()
    {
        return $this->response;
    }
}