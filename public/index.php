<?php

/**
 * Test upload form
 */
require 'config.php';
require '../vendor/autoload.php';

$client = new \CatLab\CentralStorage\Client\CentralStorageClient(
    STORE_URL,
    CONSUMER_KEY,
    CONSUMER_SECRET
);

if (isset($_FILES['file'])) {

    $file = $_FILES['file'];

    $uploadedFile = new \Illuminate\Http\UploadedFile(
        $file['tmp_name'],
        $file['name']
    );

    try {
        $asset = $client->store($uploadedFile);
    } catch (\CatLab\CentralStorage\Client\Exceptions\StorageServerException $e) {
        echo '<h1>ERROR</h1>';
        echo '<h2>' . $e->getMessage() . '</h2>';
        echo $e->getResponse();
    }

    dd($asset);

} else {
    include 'templates/uploadform.php';
}