<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LicenseActivationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'license_key' => ['required', 'string', 'max:255'],
        ]);

        // Placeholder activation response until full licensing workflow is implemented.
        return response()->json([
            'message' => 'License activation endpoint is available.',
            'status' => 'pending_implementation',
        ]);
    }
}