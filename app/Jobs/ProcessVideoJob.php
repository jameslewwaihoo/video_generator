<?php

namespace App\Jobs;

use App\Models\VideoJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class ProcessVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 21600;

    private int $videoJobId;

    public function __construct(int $videoJobId)
    {
        $this->videoJobId = $videoJobId;
    }

    public function handle()
    {
        $videoJob = VideoJob::findOrFail($this->videoJobId);
        $output = storage_path('app/generated/video-job-'.$videoJob->id.'-'.now()->format('Ymd-His').'.mp4');

        $videoJob->update([
            'status' => 'processing',
            'started_at' => now(),
            'output_file' => basename($output),
            'error' => null,
        ]);

        $arguments = [
            '--title' => $videoJob->title,
            '--duration' => $videoJob->duration,
            '--output' => $output,
            '--ffmpeg' => $videoJob->ffmpeg_path ?: 'ffmpeg',
        ];

        foreach ($videoJob->video_files ?: [] as $videoFile) {
            $arguments['--video'][] = $videoFile;
        }

        foreach ($videoJob->music_files ?: [] as $musicFile) {
            $arguments['--music'][] = $musicFile;
        }

        if ($videoJob->hide_timer) {
            $arguments['--no-timer'] = true;
        }

        $exitCode = Artisan::call('video:generate', $arguments);
        $commandOutput = trim(Artisan::output());

        if ($exitCode !== 0) {
            $videoJob->update([
                'status' => 'failed',
                'log' => $commandOutput,
                'error' => 'FFmpeg exited with code '.$exitCode.'.',
                'finished_at' => now(),
            ]);

            return;
        }

        $videoJob->update([
            'status' => 'completed',
            'log' => $commandOutput,
            'finished_at' => now(),
        ]);
    }

    public function failed(Throwable $exception)
    {
        $videoJob = VideoJob::find($this->videoJobId);

        if ($videoJob === null) {
            return;
        }

        $videoJob->update([
            'status' => 'failed',
            'error' => $exception->getMessage(),
            'finished_at' => now(),
        ]);
    }
}
