<?php

namespace App;

use PierreMiniggio\ConfigProvider\ConfigProvider;
use PierreMiniggio\GithubActionRunStarterAndArtifactDownloader\GithubActionRunStarterAndArtifactDownloaderFactory;
use PierreMiniggio\MP4YoutubeVideoDownloader\Downloader;
use Throwable;

class App
{

    protected const CLIP_LENGTH = 15;

    public function run(string $path, ?string $queryParameters, ?string $authHeader): void
    {
        $projectFolder = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR;

        $configProvider = new ConfigProvider($projectFolder);
        $config = $configProvider->get();

        $yt1dApiRepoConfig = $config['yt1dApiRepo'] ?? null;

        if (! $yt1dApiRepoConfig) {
            http_response_code(500);
            echo json_encode(['error' => 'Unset yt1dApiRepo config error']);
            
            return;
        }

        $githubActionToken = $yt1dApiRepoConfig['token'] ?? null;

        if (! $githubActionToken) {
            http_response_code(500);
            echo json_encode(['error' => 'Unset githubActionToken config error']);
            
            return;
        }

        $yt1dApiOwner = $yt1dApiRepoConfig['owner'] ?? null;

        if (! $yt1dApiOwner) {
            http_response_code(500);
            echo json_encode(['error' => 'Unset yt1dApiOwner config error']);
            
            return;
        }

        $yt1dApiRepo = $yt1dApiRepoConfig['repo'] ?? null;

        if (! $yt1dApiRepo) {
            http_response_code(500);
            echo json_encode(['error' => 'Unset yt1dApiRepo config error']);
            
            return;
        }
        
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

        set_time_limit(600);

        if (file_exists($mp4)) {
            goto getHighlight;
        }

        $downloader = new Downloader();
        try {
            $downloader->download('https://www.youtube.com/watch?v=' . $videoId, $mp4);
        } catch (Throwable $e) {
            if ($e->getMessage() !== 'Best link not found') {
                echo json_encode(['error' => $e->getMessage()]);
                http_response_code(500);

                return;
            }
            
            shell_exec('youtube-dl https://youtu.be/' . $videoId . ' -f mp4 --output ' . $mp4);
        }

        if (! file_exists($mp4)) {
            $githubActionRunStarterAndArtifactDownloader = (
                new GithubActionRunStarterAndArtifactDownloaderFactory()
            )->make();

            $artifacts = $githubActionRunStarterAndArtifactDownloader->runActionAndGetArtifacts(
                $githubActionToken,
                $yt1dApiOwner,
                $yt1dApiRepo,
                'get-link.yml',
                60
            );

            if (! $artifacts) {
                http_response_code(500);
                echo json_encode(['error' => 'No artifact']);
                
                return;
            }
    
            $artifact = $artifacts[0];
    
            if (! file_exists($artifact)) {
                http_response_code(500);
                echo json_encode(['error' => 'Artifact missing']);
                
                return;
            }
    
            $downloadLink = trim(file_get_contents($artifact));
            unlink($artifact);

            $fp = fopen($mp4, 'w+');
            $ch = curl_init($downloadLink);
            curl_setopt($ch, CURLOPT_TIMEOUT, 50);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_exec($ch);
            curl_close($ch);
            fclose($fp);
        }

        if (! file_exists($mp4)) {
            http_response_code(500);
            echo json_encode(['error' => 'mp4 file failed to get downloaded']);
            die;
        }

        getHighlight:
        $probedDuration = shell_exec('ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($mp4));
        $splitDuration = explode('.', $probedDuration);

        if (count($splitDuration) === 1) {
            goto convert;
        }

        $seconds = (int) $splitDuration[0];

        if ($seconds <= self::CLIP_LENGTH) {
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

        if (! file_exists($cutMp4)) {
            http_response_code(500);
            echo json_encode(['error' => 'Cut mp4 file failed to get created']);
            die;
        }

        setCutMp4ToMp4:
        $mp4 = $cutMp4;

        convert:

        shell_exec('ffmpeg -i ' . escapeshellarg($mp4) . ' -c:v libvpx -quality good -cpu-used 0 -b:v 7000k -qmin 10 -qmax 42 -maxrate 500k -bufsize 1500k -threads 8 -vf scale=-1:1080 -c:a libvorbis -b:a 192k -f webm ' . escapeshellarg($webm));
        shell_exec('ffmpeg -i ' . escapeshellarg($mp4) . ' ' . escapeshellarg($mp3));
        
        if (! file_exists($webm) || ! file_exists($mp3)) {
            http_response_code(500);
            echo json_encode(['error' => 'webm and mp3 files are missing']);
            die;
        }

        done:
        http_response_code(204);
    }
}
