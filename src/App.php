<?php

namespace App;

use PierreMiniggio\MP4YoutubeVideoDownloader\Downloader;
use Throwable;

class App
{

    protected const CLIP_LENGTH = 30;

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
        $mp4 = $cacheFolder . $videoId . '.mp4';
        $webm = $videoFolder . $videoId . '.webm';
        $mp3 = $videoFolder . $videoId . '.mp3';

        if (file_exists($webm) && file_exists($mp3)) {
            goto done;
        }

        if (file_exists($mp4)) {
            goto getHighlight;
        }

        $downloader = new Downloader();
        try {
            $downloader->download('https://www.youtube.com/watch?v=' . $videoId, $mp4);
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
            http_response_code(500);

            return;
        }

        getHighlight:
        $probedDuration = shell_exec('ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($mp4));
        $splitDuration = explode('.', $probedDuration);

        if (count($splitDuration) === 1) {
            goto convert;
        }

        $seconds = (int) $splitDuration[0];

        if ($seconds <= 10) {
            goto convert;
        }

        $cutMp4 = $cacheFolder . $videoId . '_cut.mp4';

        if (file_exists($cutMp4)) {
            goto setCutMp4ToMp4;
        }

        $startTime = rand(0, $seconds - self::CLIP_LENGTH);
        shell_exec(
            'ffmpeg -ss '
            . gmdate('H:i:s', $startTime)
            . ' -i '
            . escapeshellarg($mp4)
            . ' -to '
            . gmdate('H:i:s', self::CLIP_LENGTH)
            . ' -c copy '
            . escapeshellarg($cutMp4)
        );

        setCutMp4ToMp4:
        $mp4 = $cutMp4;

        convert:

        shell_exec('ffmpeg -i ' . escapeshellarg($mp4) . ' -c:v libvpx -quality good -cpu-used 0 -b:v 7000k -qmin 10 -qmax 42 -maxrate 500k -bufsize 1500k -threads 8 -vf scale=-1:1080 -c:a libvorbis -b:a 192k -f webm ' . escapeshellarg($webm));
        shell_exec('ffmpeg -i ' . escapeshellarg($mp4) . ' ' . escapeshellarg($mp3));

        done:
        http_response_code(204);
    }
}
