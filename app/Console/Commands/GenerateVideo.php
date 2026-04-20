<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class GenerateVideo extends Command
{
    protected $signature = 'video:generate
        {--title=Relaxing Music : Text shown at the top of the video}
        {--duration=3600 : Video length in seconds}
        {--output= : Output MP4 path}
        {--ffmpeg= : FFmpeg binary path}
        {--video=* : Video asset filename to include}
        {--music=* : Music asset filename to include}
        {--no-timer : Hide the elapsed timer overlay}';

    protected $description = 'Generate an MP4 from looping stock video and music playlists.';

    public function handle()
    {
        $duration = (int) $this->option('duration');

        if ($duration < 1) {
            $this->error('The --duration option must be at least 1 second.');

            return Command::FAILURE;
        }

        $videos = $this->playlistAssets(storage_path('app/assets/videos'), ['mp4', 'mov', 'm4v', 'webm'], (array) $this->option('video'));
        $music = $this->playlistAssets(storage_path('app/assets/music'), ['mp3', 'wav', 'm4a', 'aac', 'flac'], (array) $this->option('music'));

        if ($videos === []) {
            $this->error('No video assets found in storage/app/assets/videos.');

            return Command::FAILURE;
        }

        if ($music === []) {
            $this->error('No music assets found in storage/app/assets/music.');

            return Command::FAILURE;
        }

        $output = $this->outputPath();
        $ffmpeg = (string) ($this->option('ffmpeg') ?: config('video.ffmpeg_path', 'ffmpeg'));
        $filter = $this->videoFilter((string) $this->option('title'), ! $this->option('no-timer'), $ffmpeg);
        $musicDuration = 0.0;
        $musicPlaylist = $this->repeatPlaylistToDuration($music, $duration, $ffmpeg, $musicDuration);
        $renderDuration = (int) ceil($musicDuration) + 1;
        $videoDuration = 0.0;
        $videoPlaylist = $this->repeatPlaylistToDuration($videos, $renderDuration, $ffmpeg, $videoDuration);
        $videoList = $this->writeConcatList($videoPlaylist, 'video-generator-videos-');
        $musicList = $this->writeConcatList($musicPlaylist, 'video-generator-music-');

        $command = [
            $ffmpeg,
            '-y',
            '-f',
            'concat',
            '-safe',
            '0',
            '-an',
            '-i',
            $videoList,
            '-f',
            'concat',
            '-safe',
            '0',
            '-i',
            $musicList,
            '-t',
            (string) $renderDuration,
            '-vf',
            $filter,
            '-map',
            '0:v:0',
            '-map',
            '1:a:0',
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-profile:v',
            'main',
            '-level',
            '4.0',
            '-pix_fmt',
            'yuv420p',
            '-tag:v',
            'avc1',
            '-c:a',
            'aac',
            '-b:a',
            '192k',
            '-ar',
            '48000',
            '-movflags',
            '+faststart',
            '-shortest',
            $output,
        ];

        $this->info('Video playlist:');
        foreach ($videoPlaylist as $video) {
            $this->info('- '.$video);
        }

        $this->info('Music playlist:');
        foreach ($musicPlaylist as $track) {
            $this->info('- '.$track);
        }

        $this->info('Requested duration: '.$duration.' seconds');
        $this->info('Render duration: '.$renderDuration.' seconds, extended to finish the last selected song.');
        $this->info('Output: '.$output);

        $process = new Process($command, base_path(), null, null, null);
        $process->setTimeout(null);
        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        @unlink($videoList);
        @unlink($musicList);

        if (! $process->isSuccessful()) {
            $this->error('FFmpeg failed. Confirm FFmpeg is installed and set FFMPEG_PATH=/full/path/to/ffmpeg in .env if needed.');

            return Command::FAILURE;
        }

        $this->info('Video generated successfully.');

        return Command::SUCCESS;
    }

    private function playlistAssets(string $directory, array $extensions, array $selectedFiles = []): array
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $selectedFiles = array_values(array_filter(array_map('basename', $selectedFiles)));
        $files = array_values(array_filter(scandir($directory) ?: [], function ($file) use ($directory, $extensions) {
            $path = $directory.DIRECTORY_SEPARATOR.$file;

            return is_file($path) && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $extensions, true);
        }));

        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        if ($selectedFiles !== []) {
            $files = array_values(array_filter($files, function ($file) use ($selectedFiles) {
                return in_array($file, $selectedFiles, true);
            }));
        }

        return array_map(function ($file) use ($directory) {
            return $directory.DIRECTORY_SEPARATOR.$file;
        }, $files);
    }

    private function repeatPlaylistToDuration(array $files, int $duration, string $ffmpeg, ?float &$totalDuration = null): array
    {
        $playlist = [];
        $runningDuration = 0.0;
        $durations = [];
        $ffprobe = $this->ffprobePath($ffmpeg);

        foreach ($files as $file) {
            $fileDuration = $this->mediaDuration($ffprobe, $file);
            $durations[$file] = $fileDuration > 0 ? $fileDuration : 1.0;
        }

        while ($runningDuration < $duration) {
            foreach ($files as $file) {
                $playlist[] = $file;
                $runningDuration += $durations[$file];

                if ($runningDuration >= $duration) {
                    break;
                }
            }
        }

        $totalDuration = $runningDuration;

        return $playlist;
    }

    private function mediaDuration(string $ffprobe, string $file): float
    {
        $process = new Process([
            $ffprobe,
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $file,
        ], base_path(), null, null, 15);
        $process->run();

        if (! $process->isSuccessful()) {
            return 0.0;
        }

        return (float) trim($process->getOutput());
    }

    private function ffprobePath(string $ffmpeg): string
    {
        if ($ffmpeg === 'ffmpeg') {
            return 'ffprobe';
        }

        return dirname($ffmpeg).DIRECTORY_SEPARATOR.'ffprobe';
    }

    private function writeConcatList(array $files, string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        $lines = array_map(function ($file) {
            return "file '".$this->escapeConcatPath($file)."'";
        }, $files);

        file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);

        return $path;
    }

    private function escapeConcatPath(string $path): string
    {
        return str_replace("'", "'\\''", $path);
    }

    private function outputPath(): string
    {
        $output = (string) ($this->option('output') ?: '');

        if ($output === '') {
            $directory = storage_path('app/generated');

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            return $directory.'/video-'.now()->format('Ymd-His').'.mp4';
        }

        if (! $this->isAbsolutePath($output)) {
            $output = base_path($output);
        }

        $directory = dirname($output);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $output;
    }

    private function videoFilter(string $title, bool $showTimer, string $ffmpeg): string
    {
        $filters = [
            'scale=1920:1080:force_original_aspect_ratio=increase',
            'crop=1920:1080',
        ];

        if (! $this->supportsDrawText($ffmpeg)) {
            $this->warn('This FFmpeg build does not include the drawtext filter. Rendering without text overlays.');

            return implode(',', $filters);
        }

        $filters[] = 'drawtext='.$this->drawTextOptions([
                'text' => $this->escapeDrawText($title),
                'x' => '(w-text_w)/2',
                'y' => '80',
                'fontsize' => '56',
                'fontcolor' => 'white',
                'box' => '1',
                'boxcolor' => 'black@0.45',
                'boxborderw' => '16',
        ]);

        if ($showTimer) {
            $filters[] = 'drawtext='.$this->drawTextOptions([
                'text' => '%{pts\:hms}',
                'x' => 'w-text_w-60',
                'y' => 'h-text_h-50',
                'fontsize' => '40',
                'fontcolor' => 'white',
                'box' => '1',
                'boxcolor' => 'black@0.45',
                'boxborderw' => '12',
            ]);
        }

        return implode(',', $filters);
    }

    private function supportsDrawText(string $ffmpeg): bool
    {
        $process = new Process([$ffmpeg, '-hide_banner', '-filters'], base_path(), null, null, 10);
        $process->run();

        if (! $process->isSuccessful()) {
            return false;
        }

        return strpos($process->getOutput(), ' drawtext ') !== false || strpos($process->getErrorOutput(), ' drawtext ') !== false;
    }

    private function drawTextOptions(array $options): string
    {
        $font = $this->fontPath();

        if ($font !== null) {
            $options = ['fontfile' => $this->escapeDrawText($font)] + $options;
        }

        $parts = [];

        foreach ($options as $key => $value) {
            if (in_array($key, ['fontfile', 'text'], true)) {
                $value = "'".$value."'";
            }

            $parts[] = $key.'='.$value;
        }

        return implode(':', $parts);
    }

    private function fontPath(): ?string
    {
        $candidates = [
            '/System/Library/Fonts/Supplemental/Arial.ttf',
            '/System/Library/Fonts/Supplemental/Helvetica.ttf',
            '/Library/Fonts/Arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function escapeDrawText(string $value): string
    {
        return str_replace(
            ['\\', ':', "'", ',', "\n", "\r"],
            ['\\\\', '\:', "\\'", '\,', ' ', ' '],
            $value
        );
    }

    private function isAbsolutePath(string $path): bool
    {
        return substr($path, 0, 1) === '/' || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}
