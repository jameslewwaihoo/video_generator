<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Video Generator</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Arial, Helvetica, sans-serif;
            line-height: 1.5;
            color: #202124;
            background: #f4f6f5;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
        }

        main {
            width: min(1120px, calc(100% - 32px));
            margin: 0 auto;
            padding: 32px 0 48px;
        }

        h1,
        h2,
        p {
            margin-top: 0;
        }

        h1 {
            font-size: 32px;
            margin-bottom: 8px;
        }

        h2 {
            font-size: 20px;
            margin-bottom: 16px;
        }

        .lead {
            max-width: 720px;
            color: #4b5563;
            margin-bottom: 28px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .panel {
            background: #ffffff;
            border: 1px solid #d9dedb;
            border-radius: 8px;
            padding: 20px;
        }

        .panel.full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            font-weight: 700;
            margin-bottom: 6px;
        }

        input[type="text"],
        input[type="number"],
        input[type="file"] {
            width: 100%;
            min-height: 42px;
            border: 1px solid #b8c0bc;
            border-radius: 8px;
            padding: 9px 11px;
            font: inherit;
            background: #ffffff;
        }

        input[type="checkbox"] {
            transform: translateY(1px);
        }

        .field {
            margin-bottom: 16px;
        }

        .row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        button,
        .button {
            display: inline-flex;
            align-items: center;
            min-height: 40px;
            border: 0;
            border-radius: 8px;
            padding: 9px 14px;
            font: inherit;
            font-weight: 700;
            color: #ffffff;
            background: #146c43;
            text-decoration: none;
            cursor: pointer;
        }

        button.secondary {
            background: #38546b;
        }

        button.small {
            min-height: 32px;
            padding: 5px 10px;
            font-size: 14px;
        }

        .notice,
        .errors,
        pre {
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 20px;
        }

        .notice {
            background: #e7f4ea;
            border: 1px solid #b7dfc0;
        }

        .errors {
            background: #fce8e6;
            border: 1px solid #f2b8b5;
        }

        ul {
            padding-left: 20px;
            margin-bottom: 0;
        }

        .empty {
            color: #6b7280;
        }

        pre {
            overflow-x: auto;
            background: #202124;
            color: #f8fafc;
            white-space: pre-wrap;
        }

        .hint {
            color: #6b7280;
            font-size: 14px;
        }

        video {
            display: block;
            width: min(420px, 100%);
            margin-top: 8px;
            background: #000000;
            border-radius: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            border-bottom: 1px solid #d9dedb;
            padding: 10px 8px;
            text-align: left;
            vertical-align: top;
        }

        th {
            color: #4b5563;
            font-size: 14px;
        }

        .status {
            display: inline-block;
            min-width: 88px;
            border-radius: 8px;
            padding: 4px 8px;
            text-align: center;
            font-size: 13px;
            font-weight: 700;
            background: #eef0f2;
        }

        .status.completed {
            background: #d9f2df;
            color: #0f5132;
        }

        .status.processing {
            background: #fff1c2;
            color: #6f4e00;
        }

        .status.failed {
            background: #fce1df;
            color: #842029;
        }

        @media (max-width: 760px) {
            main {
                width: min(100% - 24px, 1120px);
                padding-top: 24px;
            }

            .grid,
            .row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <main>
        <h1>Video Generator</h1>
        <p class="lead">Upload a stock video and music track, then queue a render. Keep a queue worker running while videos are processing.</p>

        @if (session('status'))
            <div class="notice">{{ session('status') }}</div>
        @endif

        @if (! $ffmpegAvailable)
            <div class="errors">
                <strong>FFmpeg is not available at "{{ $defaultFfmpegPath }}".</strong>
                <p class="hint">Install FFmpeg or set <code>FFMPEG_PATH=/full/path/to/ffmpeg</code> in <code>laravel/.env</code>, then run <code>php artisan config:clear</code>.</p>
            </div>
        @elseif (! $drawTextAvailable)
            <div class="notice">
                <strong>FFmpeg is available, but the drawtext filter is missing.</strong>
                <p class="hint">Videos will render without title and timer overlays until FFmpeg is installed with drawtext support.</p>
            </div>
        @endif

        @if ($errors->any())
            <div class="errors">
                <strong>Fix this first:</strong>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid">
            <section class="panel">
                <h2>Upload Video</h2>
                <form action="/assets/videos" method="post" enctype="multipart/form-data">
                    @csrf
                    <div class="field">
                        <label for="video">Video file</label>
                        <input id="video" type="file" name="video" accept=".mp4,.mov,.m4v,.webm" required>
                        <div class="hint">Current PHP upload limit: {{ $uploadMaxFilesize }} per file, {{ $postMaxSize }} per request.</div>
                    </div>
                    <button class="secondary" type="submit">Upload video</button>
                </form>
            </section>

            <section class="panel">
                <h2>Upload Music</h2>
                <form action="/assets/music" method="post" enctype="multipart/form-data">
                    @csrf
                    <div class="field">
                        <label for="music">Music file</label>
                        <input id="music" type="file" name="music" accept=".mp3,.wav,.m4a,.aac,.flac" required>
                        <div class="hint">Current PHP upload limit: {{ $uploadMaxFilesize }} per file, {{ $postMaxSize }} per request.</div>
                    </div>
                    <button class="secondary" type="submit">Upload music</button>
                </form>
            </section>

            <section class="panel full">
                <h2>Generate MP4</h2>
                <form action="/generate" method="post">
                    @csrf
                    <div class="row">
                        <div class="field">
                            <label for="title">Title overlay</label>
                            <input id="title" type="text" name="title" value="{{ old('title', 'Relaxing Music') }}" maxlength="120" required>
                        </div>
                        <div class="field">
                            <label for="duration">Duration in seconds</label>
                            <input id="duration" type="number" name="duration" value="{{ old('duration', 10) }}" min="1" max="21600" required>
                            <div class="hint">Use 10 seconds for the first test render.</div>
                        </div>
                    </div>

                    <div class="field">
                        <label for="ffmpeg">FFmpeg path</label>
                        <input id="ffmpeg" type="text" name="ffmpeg" value="{{ old('ffmpeg', $defaultFfmpegPath) }}">
                        <div class="hint">Default comes from FFMPEG_PATH in .env. Use a full path if the worker cannot find FFmpeg.</div>
                    </div>

                    <div class="field">
                        <label>
                            <input type="checkbox" name="no_timer" value="1" {{ old('no_timer') ? 'checked' : '' }}>
                            Hide elapsed timer
                        </label>
                    </div>

                    <button type="submit">Queue render</button>
                </form>
            </section>

            <section class="panel full">
                <h2>Render Jobs</h2>
                @if ($jobs->count())
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Status</th>
                                <th>Title</th>
                                <th>Duration</th>
                                <th>Output</th>
                                <th>Action</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($jobs as $job)
                                <tr>
                                    <td>#{{ $job->id }}</td>
                                    <td><span class="status {{ $job->status }}">{{ $job->status }}</span></td>
                                    <td>{{ $job->title }}</td>
                                    <td>{{ $job->duration }}s</td>
                                    <td>
                                        @if ($job->isComplete())
                                            <a href="/generated/{{ $job->output_file }}">Download</a>
                                            <video controls preload="metadata" src="/generated/{{ $job->output_file }}/watch"></video>
                                        @elseif ($job->status === 'failed')
                                            {{ $job->log ?: $job->error ?: 'Render failed.' }}
                                        @else
                                            Waiting for output
                                        @endif
                                    </td>
                                    <td>
                                        @if ($job->status === 'failed')
                                            <form action="/jobs/{{ $job->id }}/retry" method="post">
                                                @csrf
                                                <button class="small secondary" type="submit">Retry</button>
                                            </form>
                                        @else
                                            <span class="hint">-</span>
                                        @endif
                                    </td>
                                    <td>{{ $job->updated_at->format('Y-m-d H:i:s') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="hint">Refresh this page to update job status.</p>
                @else
                    <p class="empty">No render jobs yet.</p>
                @endif
            </section>

            <section class="panel">
                <h2>Available Assets</h2>
                <strong>Videos</strong>
                @if ($videos)
                    <ul>
                        @foreach ($videos as $video)
                            <li>{{ $video }}</li>
                        @endforeach
                    </ul>
                @else
                    <p class="empty">No video files uploaded yet.</p>
                @endif

                <br>
                <strong>Music</strong>
                @if ($music)
                    <ul>
                        @foreach ($music as $track)
                            <li>{{ $track }}</li>
                        @endforeach
                    </ul>
                @else
                    <p class="empty">No music files uploaded yet.</p>
                @endif
            </section>

            <section class="panel">
                <h2>Generated Files</h2>
                @if ($outputs)
                    <ul>
                        @foreach ($outputs as $output)
                            <li>
                                <a href="/generated/{{ $output }}">{{ $output }}</a>
                                <video controls preload="metadata" src="/generated/{{ $output }}/watch"></video>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="empty">No generated MP4 files yet.</p>
                @endif
            </section>

            <section class="panel">
                <h2>Worker Command</h2>
                <p>Run this in a terminal from the Laravel directory while jobs are pending or processing.</p>
                <pre>php artisan queue:work --timeout=21600</pre>
            </section>
        </div>
    </main>
</body>
</html>
