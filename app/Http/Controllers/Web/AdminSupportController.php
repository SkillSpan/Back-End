<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Admin\SupportController as ApiSupportController;
use App\Models\User;
use App\Services\Profile\AdminProfileService;
use App\Services\Support\SupportRequestService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session-authenticated twin of the admin support inbox API.
 *
 * The panel runs on a normal web session, so its JavaScript authenticates with
 * the session cookie rather than a Bearer token. Every rule — the visibility
 * scope, the claim-on-first-reply behaviour, the read tracking, who may delete
 * what — is inherited from the API controller rather than re-implemented, so
 * the two can never disagree about who may read a thread.
 *
 * There is nothing to override for the JSON endpoints: the support payloads
 * contain no route-built links, so the API controller's output is already
 * browser-appropriate. The one addition below is an image route, which has no
 * place on the token API because the panel is the only consumer of it.
 */
class AdminSupportController extends ApiSupportController
{
    public function __construct(
        SupportRequestService $supportService,
        private readonly AdminProfileService $profileService,
    ) {
        parent::__construct($supportService);
    }

    /**
     * GET /admin/support/avatar/{user}
     *
     * A panel colleague's photo, for the avatar beside their messages.
     *
     * WHY IT IS NOT A FIELD ON THE MESSAGE PAYLOAD
     * --------------------------------------------
     * The thread is read through the same resource by the panel and by the
     * learner API, and a URL built for one of them is wrong for the other. The
     * payload therefore carries `sender_id` and the panel builds the URL from
     * it — which keeps the shared resource free of route knowledge, and keeps
     * this image out of the learner's API entirely.
     *
     * A person with no photo answers 404 rather than a placeholder: the panel
     * falls back to their initials, so "no photo" is a miss, not a grey person.
     */
    public function avatar(Request $request, User $user): Response
    {
        // The same two audiences that may open the inbox. Anyone else has no
        // business resolving a staff user id into a face.
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
            // The stored mime is the one *we* produced when re-encoding, not
            // the one the client claimed, so echoing it back is safe.
            'Content-Type' => (string) $profile->avatar_mime,
            'Content-Length' => (string) strlen($binary),
            /*
             * Deliberately shorter than the year-long cache on the profile's own
             * avatar. That route is handed a `?v=` token that changes with the
             * bytes; here the client has no token, because the message payload
             * does not carry one. Five minutes keeps the repeated requests of a
             * scroll cheap while still letting a changed photo appear on its own.
             */
            'Cache-Control' => 'private, max-age=300',
            // Even a correctly-typed image must not be sniffed into something
            // executable.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
