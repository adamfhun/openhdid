<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Http\Controllers\Controller;
use App\Models\NewsPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Published news for signed-in clients. Authors are never exposed.
 */
class NewsController extends Controller
{
    /**
     * @response array{data: list<array{id: string, title: string, body_html: string, published_at: string}>, meta: array{total: int}}
     */
    public function index(Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->query('limit', 20)));

        $posts = NewsPost::query()->published()->latest('published_at')->limit($limit)->get();

        return response()->json([
            'data' => $posts->map(fn (NewsPost $post) => [
                'id' => $post->id,
                'title' => $post->title,
                'body_html' => $post->bodyHtml(),
                'published_at' => $post->published_at->toIso8601String(),
            ])->values(),
            'meta' => ['total' => NewsPost::query()->published()->count()],
        ]);
    }
}
