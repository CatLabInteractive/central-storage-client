## Central Storage Laravel Client

Central Storage is a storage engine built in Laravel. It includes duplicate upload detection, supports on the fly image
(but cached) image resize and allows you to set 'Processors' that handle more complex file transformations like video
transcoding etc.

## Setup
Please follow the setup instructions described in the
[central storage project page](https://github.com/catlabinteractive/central-storage) to setup to storage system. 
Once that has been setup, you can include this library in your project to start storing assets.

## Setting up your client project (in Laravel)
Central Storage provides a standard REST API and is thus consumable by any language or framework. We will focus on the
existing Laravel client here. Note that it is a trivial task to implement a new client, as there is only a few methods
to implement.

In your Laravel project, run
```composer require catlabinteractive/central-storage-client```

Then, wherever you want to upload a file, initialize the client:

```php
$centralStorageClient = new CentralStorageClient(
    'https://your-central-storage-url.com',
    'your_key',
    'your_secret',
    'cdn_frontend_url' // (optional)
);
```

Or, if you like, you can use the provider that uses the default configuration files:
```php
    'providers' => [
    
        [...]
        
        CatLab\CentralStorage\Client\CentralStorageServiceProvider::class,
    
    ],
    
    'aliases' => [
    
        [...]
        
        'CentralStorage' => CatLab\CentralStorage\Client\CentralStorageClientFacade::class,
    
    ]
]
```

The PHP client consumes Symfony's File objects. That means you can upload files straight from Laravel. The client returns
an Eloquent model 'Asset', which can be saved directly to a database (migration file is available in `central-storage-client/database`).

```php
<?php

use App\Models\Attachments\Asset;
use Illuminate\Http\Request;

class AssetController
{
    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function upload(Request $request)
    {
        $file = $request->file()->first();
        if (!$file) {
            abort(400, 'Please provide a valid file.');
        }

        if (!$file->isValid()) {
            abort(400, 'File not valid: ' . $file->getErrorMessage());
        }

        /** @var Asset $asset */
        $asset = \CentralStorage::store($file);
        $asset->save();

        return response()->json($asset->getData());
    }
}
```
