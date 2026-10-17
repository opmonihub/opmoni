<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnTableYoutubeVideoLessons extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('youtube_video_lessons', function (Blueprint $table) {
            $table->increments('id');

            $table->string('url_from_youtube', 191);
            $table->string('url_from_app', 191);
            $table->string('page_name', 191);
            $table->string('background_color', 10);
            $table->string('label_button', 30);
            $table->string('icone', 40)->default('fas fa-play');

            // alter table youtube_video_lessons add column page_name varchar(191);
            // alter table youtube_video_lessons add column icone varchar(40) default('fas fa-play');

            // alter table youtube_video_lessons add column background_color varchar(10);
            // alter table youtube_video_lessons add column label_button varchar(30);
            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('youtube_video_lessons');
        
    }
}
