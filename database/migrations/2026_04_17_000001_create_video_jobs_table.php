<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVideoJobsTable extends Migration
{
    public function up()
    {
        Schema::create('video_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedInteger('duration');
            $table->string('ffmpeg_path')->default('ffmpeg');
            $table->boolean('hide_timer')->default(false);
            $table->string('status')->default('pending')->index();
            $table->string('output_file')->nullable();
            $table->longText('log')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('video_jobs');
    }
}
