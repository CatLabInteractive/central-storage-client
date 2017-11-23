<?php

namespace CatLab\CentralStorage\Client\Models;

use CatLab\CentralStorage\Client\Interfaces\CentralStorageClient;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Asset
 * @package CatLab\CentralStorage\Models
 */
class Asset extends Model
{
    public $timestamps = true;

    protected $fillable = [
        'key',
        'name',
        'width',
        'height',
        'size',
        'type',
        'mimetype'
    ];

    protected $asset_key_column = 'asset_key';

    /**
     * @param string $name
     * @return $this
     */
    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param $key
     * @return $this
     */
    public function setAssetKey($key)
    {
        $this->{$this->asset_key_column} = $key;
        return $this;
    }

    /**
     * @return string
     */
    public function getAssetKey()
    {
        return $this->{$this->asset_key_column};
    }

    /**
     * @param $width
     * @param $height
     * @return $this
     */
    public function setDimensions($width, $height)
    {
        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    /**
     * @return array
     */
    public function getDimensions()
    {
        return [
            'width' => $this->width,
            'height' => $this->height
        ];
    }

    /**
     * @return int
     */
    public function getSize()
    {
        return $this->size;
    }

    /**
     * @param int $size
     * @return Asset
     */
    public function setSize($size)
    {
        $this->size = $size;
        return $this;
    }

    /**
     * @return string
     */
    public function getMimeType()
    {
        return $this->mimetype;
    }

    /**
     * @param string $mimeType
     * @return Asset
     */
    public function setMimeType($mimeType)
    {
        $this->mimetype = $mimeType;
        return $this;
    }

    /**
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @param string $type
     * @return Asset
     */
    public function setType($type)
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @param array $parameters
     * @return string
     */
    public function getUrl(array $parameters = [])
    {
        return app(CentralStorageClient::class)->getAssetUrl($this, $parameters);
    }

    /**
     * Delete the asset from here, and from the central server.
     * @param array $parameters
     * @return bool|null
     */
    public function delete(array $parameters = [])
    {
        $result = null;

        \DB::transaction(function () use (&$result, $parameters) {

            // First delete the parent to make sure there are no relationship issues
            $result = parent::delete();

            // Now remove the file from the remote server
            app(CentralStorageClient::class)->deleteAsset($this, $parameters);

        }, 5);

        return $result;
    }

    /**
     * @return bool
     */
    public function isImage()
    {
        return $this->type == 'image';
    }

    /**
     * @return bool
     */
    public function isAudio()
    {
        switch ($this->mimetype) {
            case 'audio/mp3':
            case 'audio/mpeg':
                return true;

            default:
                return false;
        }
    }

    /**
     * @return bool
     */
    public function isVideo()
    {
        /*
        switch ($this->mimetype) {
            case 'video/mp4':
            case 'video/mpeg':

                return true;

            default:
                return false;
        }
        */

        $parts = explode('/', $this->mimetype);
        return strtolower($parts[0]) === 'video';
    }

    /**
     * @return bool
     */
    public function isDocument()
    {
        switch ($this->mimetype) {
            case 'application/octet-stream':
                return false;

            default:
                return true;
        }
    }

    /**
     * @return bool
     */
    public function isPdf()
    {
        switch ($this->mimetype) {
            case 'application/pdf':
            case 'application/x-pdf':
            case 'application/acrobat':
            case 'applications/vnd.pdf':
            case 'text/pdf':
            case 'text/x-pdf':
                return false;

            default:
                return true;
        }
    }

    /**
     * @return bool
     */
    public function isSvg()
    {
        return $this->mimetype == 'image/svg+xml';
    }
}