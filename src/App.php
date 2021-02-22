<?php

namespace App;

use PierreMiniggio\MP4YoutubeVideoDownloader\Downloader;
use Throwable;

class App
{

    public function run(string $path, ?string $queryParameters, ?string $authHeader): void
    {
        $config = require
            __DIR__
            . DIRECTORY_SEPARATOR
            . '..'
            . DIRECTORY_SEPARATOR
            . 'config.php'
        ;
        
        if (! $authHeader || $authHeader !== 'Bearer ' . $config['apiToken']) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            
            return;
        }

        if ($path === '/') {
            http_response_code(404);

            return;
        }

        $videoId = substr($path, 1);
        $publicFolder = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR;
        $cacheFolder = $publicFolder . 'cache' . DIRECTORY_SEPARATOR;
        $videoFolder = $publicFolder . 'video' . DIRECTORY_SEPARATOR;
        $mp4 = $cacheFolder . $videoId . 'mp4';
        $webm = $videoFolder . $videoId . 'webm';
        $mp3 = $videoFolder . $videoId . 'mp3';

        $downloader = new Downloader();
        try {
            $downloader->download('https://www.youtube.com/watch?v=' . $videoId, $mp4);
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
            http_response_code(500);

            return;
        }
        


        
    }
}
