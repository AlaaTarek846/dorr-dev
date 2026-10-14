<?php

namespace Modules\Rides\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Rides\Services\Safety\RideShareService;

/**
 * The page behind a shared ride link (https://…/ride/{token}) — for family, no app or account
 * needed: a live map with the car, the ride's state, the captain's first name and the car with its
 * plate; "arrived safely" at the end (R-111, R-113). `/ride/{token}/live` is what it polls.
 */
class RideSharePageController extends Controller
{
    public function page(Request $request, string $token, RideShareService $shares): Response
    {
        $ar = str_starts_with((string) $request->getPreferredLanguage(['ar', 'en']), 'ar');
        app()->setLocale($ar ? 'ar' : 'en');
        $data = $shares->view($token);

        return response()->view('rides.share', ['data' => $data, 'token' => $token, 'ar' => $ar], $data === null ? 404 : 200)
            ->header('X-Robots-Tag', 'noindex');
    }

    public function live(Request $request, string $token, RideShareService $shares): JsonResponse
    {
        app()->setLocale(str_starts_with((string) $request->getPreferredLanguage(['ar', 'en']), 'ar') ? 'ar' : 'en');
        $data = $shares->view($token, false);

        return $data === null ? response()->json(['gone' => true], 404) : response()->json($data);
    }
}
