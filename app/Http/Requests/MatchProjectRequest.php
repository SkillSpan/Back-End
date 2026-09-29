<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Task 10 — validation for POST /api/v1/projects/{project}/match.
 *
 * The endpoint takes no request body: the only input is the route
 * parameter, so the rules are applied to `project` after it has been
 * promoted out of the route. The route is additionally constrained with
 * `whereNumber('project')`, which is why this rule is a defensive
 * backstop rather than the only guard — the catalog's
 * `GET /projects/{project}` uses the same pairing.
 */
class MatchProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is enforced by the route middleware
        // (auth:sanctum + account.active + role:learner) and by
        // ProjectMatchingSnapshotService, which re-checks project
        // visibility for the authenticated learner.
        return true;
    }

    /**
     * Route parameters are not part of the request input, so the
     * `project` parameter has to be merged in before the rules run.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['project' => $this->route('project')]);
    }

    public function rules(): array
    {
        return [
            'project' => ['required', 'integer', 'gt:0'],
        ];
    }
}
