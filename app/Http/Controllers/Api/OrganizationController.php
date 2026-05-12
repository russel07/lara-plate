<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;

class OrganizationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Organization::query()
                ->select(['id', 'name', 'slug', 'website', 'industry', 'country', 'timezone'])
                ->orderBy('name')
                ->get(),
        ]);
    }
}