<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Admin\SupportController as ApiSupportController;
use App\Models\User;
use App\Services\Profile\AdminProfileService;
use App\Services\Support\SupportRequestService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminSupportController extends ApiSupportController
{
    public function __construct(
        SupportRequestService $supportService,
        private readonly AdminProfileService $profileService,
    ) {
        parent::__construct($supportService);
    }

    public function avatar(Request $request, User $user): Response
    {
        if (! self::isSupportAgent($request->user())) {
            abort(403);
        }

        $profile = $user->adminProfile;

        if ($profile === null) {
            abort(404);
        }

        $binary = $this->profileService->avatarBinary($profile);

        if ($binary === null) {
            abort(404);
        }

        return response($binary, 200, [
            'Content-Type' => (string) $profile->avatar_mime,
            'Content-Length' => (string) strlen($binary),
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
