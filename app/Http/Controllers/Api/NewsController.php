<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Services\NewsService;
use Illuminate\Http\Request;

class NewsController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly NewsService $news) {}

    /** The live news feed for the player's country. */
    public function index(Request $request)
    {
        $company = $this->company($request);

        $this->news->ensureFresh($company->country);

        $items = $this->news->feed($company->country)->map(fn ($n) => [
            'id' => $n->id,
            'category' => $n->category,
            'severity' => $n->severity,
            'icon' => $n->icon,
            'headline' => $n->headline,
            'body' => $n->body,
            'country' => $n->country,
            'occurred_at' => $n->occurred_at?->toIso8601String(),
        ]);

        return response()->json(['data' => $items]);
    }
}
