<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\SkillsController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SkillsReferenceController extends SkillsController
{
    public function taxonomy(Request $request): JsonResponse
    {
        return parent::taxonomy($request);
    }
}
