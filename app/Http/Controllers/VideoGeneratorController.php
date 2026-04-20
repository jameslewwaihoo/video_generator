<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessVideoJob;
use App\Models\VideoJob;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Process\Process;

class VideoGeneratorController extends Controller
{
    public function index()
    {
        return view('video-generator', [
            'videos' => $this->assets('videos', ['mp4', 'mov', 'm4v', 'webm']),
            'music' => $this->assets('music', ['mp3', 'wav', 'm4a', 'aac', 'flac']),
            'outputs' => $this->outputs(),
            'jobs' => VideoJob::latest()->limit(20)->get(),
            'uploadMaxFilesize' => ini_get('upload_max_filesize'),
            'postMaxSize' => ini_get('post_max_size'),
            'defaultFfmpegPath' => config('video.ffmpeg_path', 'ffmpeg'),
            'ffmpegAvailable' => $this->ffmpegAvailable((string) config('video.ffmpeg_path', 'ffmpeg')),
            'drawTextAvailable' => $this->ffmpegSupportsDrawText((string) config('video.ffmpeg_path', 'ffmpeg')),
        ]);
    }

    public function uploadVideo(Request $request)
    {
        $request->validate([
            'video' => ['required', 'file', 'mimes:mp4,mov,m4v,webm', 'max:102400'],
        ]);

        $this->storeUpload($request->file('video'), storage_path('app/assets/videos'));

        return redirect('/')->with('status', 'Video uploaded.');
    }

    public function uploadMusic(Request $request)
    {
        $request->validate([
            'music' => ['required', 'file', 'mimes:mp3,wav,m4a,aac,flac', 'max:102400'],
        ]);

        $this->storeUpload($request->file('music'), storage_path('app/assets/music'));

        return redirect('/')->with('status', 'Music uploaded.');
    }

    public function generate(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'duration' => ['required', 'integer', 'min:1', 'max:21600'],
            'ffmpeg' => ['nullable', 'string', 'max:255'],
            'videos' => ['required', 'array', 'min:1'],
            'videos.*' => ['string'],
            'music' => ['required', 'array', 'min:1'],
            'music.*' => ['string'],
        ]);

        $videoFiles = $this->selectedAssets('videos', ['mp4', 'mov', 'm4v', 'webm'], $validated['videos']);
        $musicFiles = $this->selectedAssets('music', ['mp3', 'wav', 'm4a', 'aac', 'flac'], $validated['music']);

        if ($videoFiles === []) {
            return redirect('/')->withInput()->withErrors(['videos' => 'Select at least one valid video asset.']);
        }

        if ($musicFiles === []) {
            return redirect('/')->withInput()->withErrors(['music' => 'Select at least one valid music asset.']);
        }

        $videoJob = VideoJob::create([
            'title' => $validated['title'],
            'duration' => $validated['duration'],
            'ffmpeg_path' => $validated['ffmpeg'] ?: config('video.ffmpeg_path', 'ffmpeg'),
            'hide_timer' => $request->boolean('no_timer'),
            'video_files' => $videoFiles,
            'music_files' => $musicFiles,
            'status' => 'pending',
        ]);

        ProcessVideoJob::dispatch($videoJob->id);

        return redirect('/')
            ->with('status', 'Render job #'.$videoJob->id.' queued. Start the queue worker if it is not already running.');
    }

    public function retry(VideoJob $videoJob)
    {
        if ($videoJob->status !== 'failed') {
            return redirect('/')
                ->withErrors(['retry' => 'Only failed jobs can be retried.']);
        }

        $videoJob->update([
            'status' => 'pending',
            'output_file' => null,
            'log' => null,
            'error' => null,
            'started_at' => null,
            'finished_at' => null,
        ]);

        ProcessVideoJob::dispatch($videoJob->id);

        return redirect('/')
            ->with('status', 'Render job #'.$videoJob->id.' queued again.');
    }

    public function download(string $file): BinaryFileResponse
    {
        abort_unless($file === basename($file), 404);

        $path = storage_path('app/generated/'.$file);

        abort_unless(is_file($path), 404);

        return response()->download($path);
    }

    public function watch(string $file): BinaryFileResponse
    {
        abort_unless($file === basename($file), 404);

        $path = storage_path('app/generated/'.$file);

        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'video/mp4',
            'Content-Disposition' => 'inline; filename="'.$file.'"',
        ]);
    }

    public function destroyGenerated(string $file)
    {
        abort_unless($file === basename($file), 404);
        abort_unless(strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'mp4', 404);

        $path = storage_path('app/generated/'.$file);

        abort_unless(is_file($path), 404);

        unlink($path);

        VideoJob::where('output_file', $file)->update([
            'status' => 'deleted',
            'output_file' => null,
            'error' => 'Generated video was deleted.',
            'finished_at' => now(),
        ]);

        return redirect('/')->with('status', 'Deleted '.$file.'.');
    }

    private function storeUpload($file, string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $extension = strtolower($file->getClientOriginalExtension());
        $filename = ($name ?: 'asset').'-'.now()->format('YmdHis').'.'.$extension;

        $file->move($directory, $filename);
    }

    private function assets(string $type, array $extensions): array
    {
        $directory = storage_path('app/assets/'.$type);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $this->files($directory, $extensions);
    }

    private function selectedAssets(string $type, array $extensions, array $selected): array
    {
        $available = $this->assets($type, $extensions);
        $selected = array_values(array_filter(array_map('basename', $selected)));

        return array_values(array_filter($available, function ($file) use ($selected) {
            return in_array($file, $selected, true);
        }));
    }

    private function outputs(): array
    {
        $directory = storage_path('app/generated');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $this->files($directory, ['mp4']);
    }

    private function files(string $directory, array $extensions): array
    {
        $files = array_values(array_filter(scandir($directory) ?: [], function ($file) use ($directory, $extensions) {
            $path = $directory.DIRECTORY_SEPARATOR.$file;

            return is_file($path) && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $extensions, true);
        }));

        rsort($files);

        return $files;
    }

    private function ffmpegAvailable(string $path): bool
    {
        if ($path === 'ffmpeg') {
            $locations = explode(PATH_SEPARATOR, getenv('PATH') ?: '');

            foreach ($locations as $location) {
                if ($location !== '' && is_executable($location.DIRECTORY_SEPARATOR.'ffmpeg')) {
                    return true;
                }
            }

            return false;
        }

        return is_executable($path);
    }

    private function ffmpegSupportsDrawText(string $path): bool
    {
        if (! $this->ffmpegAvailable($path)) {
            return false;
        }

        $process = new Process([$path, '-hide_banner', '-filters'], base_path(), null, null, 5);
        $process->run();

        if (! $process->isSuccessful()) {
            return false;
        }

        return strpos($process->getOutput(), ' drawtext ') !== false || strpos($process->getErrorOutput(), ' drawtext ') !== false;
    }
}
