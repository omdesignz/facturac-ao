<?php

namespace App\Http\Controllers;

use App\Actions\RenderUserGuide;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class UserGuideController extends Controller
{
    public function index(Request $request): Response
    {
        $guide = config('user-guide');
        $chapter = $request->query('chapter', 'comecar');
        abort_unless(is_string($chapter) && in_array($chapter, array_column($guide['chapters'], 'id'), true), 404);

        return Inertia::render('Help/Guide', [
            'guide' => [
                ...$guide,
                'chapters' => array_map(fn (array $item): array => [
                    ...$item,
                    'url' => route($item['route']),
                ], $guide['chapters']),
            ],
            'selectedChapter' => $chapter,
            'progressKey' => 'facturac.guide.'.$guide['edition'].'.'.$request->user()->getAuthIdentifier(),
        ]);
    }

    public function pdf(RenderUserGuide $render): HttpResponse
    {
        return response($render->execute(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="facturac-guia-de-utilizacao.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
