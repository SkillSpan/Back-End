<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LearningActivity;
use App\Models\LearningResource;
use App\Models\RoadmapAction;
use App\Models\SkillEvaluation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoadmapLearningController extends Controller
{
    private function authorizeAction(Request $request, RoadmapAction $action): void
    {
        abort_unless($action->roadmap->studentProfile->user_id === $request->user()->id, 404);
        abort_unless($action->roadmap->status === 'active', 409, 'This roadmap has been superseded. Refresh your career journey.');
        abort_unless($action->type === 'resource', 422, 'This action is not a learning resource action.');
        abort_if(in_array($action->status, ['blocked', 'completed', 'skipped'], true)
            || count($action->blocking_prerequisite_skill_ids ?? []) > 0, 409, 'This action cannot be started or changed.');
    }

    private function resources(RoadmapAction $action)
    {
        $level = SkillEvaluation::where('student_profile_id', $action->roadmap->student_profile_id)
            ->where('skill_id', $action->target_skill_id)->orderByDesc('calculated_at')->orderByDesc('id')->value('level');
        $maximumLevel = min(5, max(1, (float) $level + 1));

        return LearningResource::query()->where('skill_id', $action->target_skill_id)
            ->whereNotNull('skill_id')->where('status', 'active')->whereNotNull('approved_at')
            ->whereNotNull('approved_by')->where('approved_at', '<=', now())
            ->whereBetween('validated_at', [now()->subDays(90), now()])
            ->where('cost', 0)->whereBetween('level', [0, $maximumLevel])->whereNotNull('prerequisites')->orderBy('id')->get()
            ->filter(fn ($resource) => filter_var($resource->url, FILTER_VALIDATE_URL)
                && strtolower(parse_url($resource->url, PHP_URL_SCHEME) ?? '') === 'https'
                && ! parse_url($resource->url, PHP_URL_USER)
                && ! parse_url($resource->url, PHP_URL_PASS))->values();
    }

    public function index(Request $request, RoadmapAction $action)
    {
        $this->authorizeAction($request, $action);
        $resources = $this->resources($action)->map(fn ($resource) => [
            'id' => $resource->id, 'title' => $resource->title, 'provider' => $resource->provider,
            'url' => $resource->url, 'language' => $resource->language,
            'level' => $resource->level, 'prerequisites' => $resource->prerequisites,
            'effort_hours' => $resource->effort_hours, 'validated_at' => $resource->validated_at,
        ]);
        $activity = LearningActivity::where('user_id', $request->user()->id)
            ->where('roadmap_action_id', $action->id)->where('source', 'roadmap_resource')->latest('id')->first();

        return response()->json(['data' => ['resources' => $resources, 'activity' => $activity]]);
    }

    public function start(Request $request, RoadmapAction $action)
    {
        $input = $request->validate(['learning_resource_id' => ['required', 'integer']]);

        return DB::transaction(function () use ($request, $action, $input) {
            // All starts and progress writes serialize on the action, including double clicks.
            $locked = RoadmapAction::whereKey($action->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('roadmap', $locked->roadmap()->lockForUpdate()->firstOrFail());
            $this->authorizeAction($request, $locked);
            $resource = $this->resources($locked)->firstWhere('id', $input['learning_resource_id']);
            abort_unless($resource, 422, 'This resource is not currently approved and available for this skill.');
            $activity = LearningActivity::where('user_id', $request->user()->id)
                ->where('roadmap_action_id', $locked->id)->where('source', 'roadmap_resource')->latest('id')->first();
            if (! $activity) {
                $activity = new LearningActivity;
                $activity->forceFill(['user_id' => $request->user()->id, 'roadmap_action_id' => $locked->id,
                    'learning_resource_id' => $resource->id, 'source' => 'roadmap_resource',
                    'status' => 'in_progress', 'progress_value' => 0, 'verification_state' => 'unverified',
                    'occurred_at' => now()])->save();
            }
            abort_unless($activity->learning_resource_id === $resource->id, 409, 'Resume the resource already started for this action.');
            $locked->forceFill(['status' => 'in_progress'])->save();

            return response()->json(['data' => ['activity' => $activity, 'url' => $resource->url]]);
        });
    }

    public function progress(Request $request, RoadmapAction $action)
    {
        $input = $request->validate(['progress_value' => ['required', 'numeric', 'between:0,100'],
            'completion_evidence' => ['nullable', 'string', 'max:5000']]);

        return DB::transaction(function () use ($request, $action, $input) {
            $locked = RoadmapAction::whereKey($action->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('roadmap', $locked->roadmap()->lockForUpdate()->firstOrFail());
            $this->authorizeAction($request, $locked);
            $activity = LearningActivity::where('user_id', $request->user()->id)
                ->where('roadmap_action_id', $locked->id)->where('source', 'roadmap_resource')->latest('id')->firstOrFail();
            $evidence = trim($input['completion_evidence'] ?? '');
            abort_if($input['progress_value'] == 100 && $evidence === '', 422, 'Describe your completion evidence before submitting 100% progress.');
            $activity->forceFill(['progress_value' => $input['progress_value'],
                'completion_evidence' => $evidence ?: null,
                'verification_state' => $input['progress_value'] == 100 ? 'pending' : 'unverified',
                'reviewed_by' => null, 'reviewed_at' => null, 'review_notes' => null,
                'status' => $input['progress_value'] == 100 ? 'pending_review' : 'in_progress'])->save();

            // Self-reported progress never completes actions or changes skill evaluations.
            return response()->json(['data' => $activity]);
        });
    }
}
