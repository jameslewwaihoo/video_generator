<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSelectedAssetsToVideoJobsTable extends Migration
{
    public function up()
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->json('video_files')->nullable()->after('hide_timer');
            $table->json('music_files')->nullable()->after('video_files');
        });
    }

    public function down()
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropColumn(['video_files', 'music_files']);
        });
    }
}
