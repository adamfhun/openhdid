<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_posts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('body');
            $table->timestamp('published_at')->nullable()->index();
            $table->foreignUuid('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_posts');
    }
};
