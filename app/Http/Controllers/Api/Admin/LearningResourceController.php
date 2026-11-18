<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LearningActivity;
use App\Models\LearningResource;
use App\Models\RoadmapAction;
use App\Traits\AuditsActions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LearningResourceController extends Controller
{
    use AuditsActions;

    public function index()
    {
        return response()->json(['data' => LearningResource::orderByDesc('id')->paginate(30)]);
    }

    public function store(Request $request)
    {
        $input = $request->validate([
            'title' => ['required', 'string', 'max:255'], 'provider' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url:https', 'max:255'], 'skill_id' => ['required', 'integer', 'exists:skills,id'],
            'language' => ['required', 'in:en,ar'], 'level' => ['required', 'numeric', 'between:0,5'],
            'prerequisites' => ['required', 'string', 'max:2000'],
        ]);
        $resource = new LearningResource($input);
        $resource->forceFill(['cost' => 0, 'type' => 'tutorial', 'status' => 'inactive', 'added_by' => $request->user()->id])->save();

        return response()->json(['data' => $resource], 201);
    }

    public function approve(Request $request, LearningResource $resource)
    {
        $request->validate(['reviewed_content_and_access' => ['required', 'accepted']]);
        abort_unless(filter_var($resource->url, FILTER_VALIDATE_URL)
            && strtolower(parse_url($resource->url, PHP_URL_SCHEME) ?? '') === 'https'
            && ! parse_url($resource->url, PHP_URL_USER) && ! parse_url($resource->url, PHP_URL_PASS)
            && $resource->skill_id && $resource->level !== null && $resource->language
            && trim($resource->prerequisites ?? '') !== '' && $resource->cost !== null && (float) $resource->cost === 0.0,
            422, 'Review and complete the free resource metadata before approval.');
        $resource->forceFill(['approved_at' => now(), 'approved_by' => $request->user()->id,
            'validated_at' => now(), 'status' => 'active'])->save();

        $this->audit($request->user()->id, 'learning_resource.approved', LearningResource::class, $resource->id);

        return response()->json(['data' => $resource]);
    }

    public function deactivate(LearningResource $resource)
    {
        $resource->forceFill(['status' => 'inactive', 'approved_at' => null, 'approved_by' => null])->save();

        return response()->json(['data' => $resource]);
    }

    public function pending()
    {
        return response()->json(['data' => LearningActivity::where('source', 'roadmap_resource')
            ->where('verification_state', 'pending')->orderBy('id')->paginate(30)]);
    }

    public function review(Request $request, LearningActivity $activity)
    {
        $input = $request->validate(['accepted' => ['required', 'boolean'], 'notes' => ['required', 'string', 'max:5000']]);

        return DB::transaction(function () use ($request, $activity, $input) {
            $action = RoadmapAction::whereKey($activity->roadmap_action_id)->lockForUpdate()->firstOrFail();
            $action->setRelation('roadmap', $action->roadmap()->lockForUpdate()->firstOrFail());
            $locked = LearningActivity::whereKey($activity->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->source === 'roadmap_resource' && $locked->verification_state === 'pending'
                && (float) $locked->progress_value === 100.0 && trim($locked->completion_evidence ?? '') !== ''
                && $action->roadmap->status === 'active' && $action->status === 'in_progress', 409, 'This submission is not pending review on an active action.');
            $locked->forceFill(['verification_state' => $input['accepted'] ? 'verified' : 'rejected',
                'status' => $input['accepted'] ? 'completed' : 'in_progress',
                'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_notes' => $input['notes']])->save();
            if ($input['accepted']) {
                $action->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
            }

            $this->audit($request->user()->id, 'learning_activity.reviewed', LearningActivity::class, $locked->id, null,
                ['accepted' => $input['accepted'], 'notes' => $input['notes'], 'roadmap_action_id' => $action->id]);

            // Resource completion is not a scored skill assessment.
            return response()->json(['data' => $locked]);
        });
    }
}
