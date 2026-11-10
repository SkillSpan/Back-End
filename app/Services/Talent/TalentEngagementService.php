<?php

namespace App\Services\Talent;

use App\Exceptions\TalentException;
use App\Models\Organization;
use App\Models\TalentContactRequest;
use App\Models\TalentList;
use App\Models\TalentListCandidate;
use App\Models\TalentNote;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TalentEngagementService
{
    public const MAX_COMPARE = 5;

    public function __construct(
        private readonly TalentDiscoveryService $talent,
    ) {}

    public function compare(User $actor, array $studentIds): array
    {
        $profiles = [];
        $unavailable = [];

        foreach (array_values(array_unique($studentIds)) as $studentId) {
            try {
                $profiles[] = $this->talent->profile($actor, (int) $studentId);
            } catch (NotFoundHttpException) {
                $unavailable[] = (int) $studentId;
            }
        }

        if (count($profiles) < 2) {
            throw new TalentException(
                'At least two visible candidates are required to compare.',
                422,
                'TALENT_COMPARE_TOO_FEW',
                [
                    'visible' => count($profiles),
                    'unavailable' => $unavailable,
                ],
            );
        }

        return [
            'students' => array_map(fn (array $profile) => [
                'id' => $profile['id'],
                'name' => $profile['name'],
                'career_role' => $profile['career_role'],
                'specialization' => $profile['specialization'],
                'university_name' => $profile['university_name'],
                'readiness' => $profile['readiness'],
                'completeness_percent' => $profile['completeness_percent'],
                'contactable' => $profile['contactable'],
                'skills' => array_slice($profile['skills'], 0, 5),
            ], $profiles),
            'skills' => $this->skillMatrix($profiles),
            'unavailable' => $unavailable,
        ];
    }

    private function skillMatrix(array $profiles): array
    {
        $rows = [];

        foreach ($profiles as $profile) {
            foreach ($profile['skills'] as $skill) {
                $name = (string) ($skill['name'] ?? '');

                if ($name === '') {
                    continue;
                }

                $rows[$name] ??= [
                    'name' => $name,
                    'category' => $skill['category'] ?? null,
                    'levels' => [],
                    'verified' => [],
                ];

                $rows[$name]['levels'][(string) $profile['id']] = $skill['level'] ?? null;
                $rows[$name]['verified'][(string) $profile['id']] = (bool) ($skill['verified'] ?? false);
            }
        }

        foreach ($rows as $name => $row) {
            foreach ($profiles as $profile) {
                $key = (string) $profile['id'];

                $rows[$name]['levels'][$key] ??= null;
                $rows[$name]['verified'][$key] ??= false;
            }
        }

        uasort($rows, function (array $a, array $b): int {
            $aHas = count(array_filter($a['levels'], fn ($level) => $level !== null));
            $bHas = count(array_filter($b['levels'], fn ($level) => $level !== null));

            return $bHas <=> $aHas ?: strcmp($a['name'], $b['name']);
        });

        return array_values($rows);
    }

    public function createList(Organization $organization, User $actor, array $data): TalentList
    {
        return TalentList::create([
            'organization_id' => $organization->id,
            'created_by' => $actor->id,
            'name' => (string) $data['name'],
            'description' => $data['description'] ?? null,
        ])->fresh(['creator:id,name']);
    }

    public function listsFor(Organization $organization, int $perPage = 15)
    {
        return TalentList::query()
            ->where('organization_id', $organization->id)
            ->with('creator:id,name')
            ->withCount('candidates')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findList(Organization $organization, int $listId): TalentList
    {
        $list = TalentList::query()
            ->where('organization_id', $organization->id)
            ->with(['creator:id,name', 'candidates.student:id,name'])
            ->find($listId);

        if ($list === null) {
            throw new TalentException(
                'The requested talent list does not exist.',
                404,
                'TALENT_LIST_NOT_FOUND',
                ['talent_list_id' => $listId],
            );
        }

        return $list;
    }

    public function addCandidate(
        Organization $organization,
        User $actor,
        int $listId,
        int $studentId,
        ?string $note = null,
    ): array {
        $list = $this->findList($organization, $listId);

        $this->assertVisible($actor, $studentId);

        $candidate = TalentListCandidate::query()->firstOrCreate(
            ['talent_list_id' => $list->id, 'student_id' => $studentId],
            ['added_by' => $actor->id, 'note' => $note],
        );

        $alreadyListed = ! $candidate->wasRecentlyCreated;

        if ($alreadyListed && $note !== null && $note !== $candidate->note) {
            $candidate->note = $note;
            $candidate->save();
        }

        return [
            'candidate' => $candidate->fresh(['student:id,name']),
            'already_listed' => $alreadyListed,
        ];
    }

    public function updateList(Organization $organization, int $listId, array $data): TalentList
    {
        $list = $this->findList($organization, $listId);

        if (array_key_exists('name', $data) && $data['name'] !== null) {
            $list->name = (string) $data['name'];
        }

        if (array_key_exists('description', $data)) {
            $list->description = $data['description'];
        }

        $list->save();

        return $list->fresh(['creator:id,name', 'candidates.student:id,name']);
    }

    public function deleteList(Organization $organization, int $listId): void
    {
        $list = $this->findList($organization, $listId);

        DB::transaction(function () use ($list): void {
            TalentListCandidate::query()->where('talent_list_id', $list->id)->delete();
            $list->delete();
        });
    }

    public function removeCandidate(Organization $organization, int $listId, int $studentId): void
    {
        $list = $this->findList($organization, $listId);

        $deleted = TalentListCandidate::query()
            ->where('talent_list_id', $list->id)
            ->where('student_id', $studentId)
            ->delete();

        if ($deleted === 0) {
            throw new TalentException(
                'That candidate is not on this list.',
                404,
                'TALENT_LIST_CANDIDATE_NOT_FOUND',
                ['talent_list_id' => $list->id, 'student_id' => $studentId],
            );
        }
    }

    public function funnel(Organization $organization): array
    {
        $saved = TalentListCandidate::query()
            ->whereHas('list', fn ($query) => $query->where('organization_id', $organization->id))
            ->distinct()
            ->count('student_id');

        $contacted = TalentContactRequest::query()
            ->where('organization_id', $organization->id)
            ->distinct()
            ->count('student_id');

        $responded = TalentContactRequest::query()
            ->where('organization_id', $organization->id)
            ->where('status', TalentContactRequest::STATUS_ACCEPTED)
            ->distinct()
            ->count('student_id');

        return [
            'saved' => $saved,
            'contacted' => $contacted,
            'responded' => $responded,
        ];
    }

    public function addNote(Organization $organization, User $actor, int $studentId, string $body): TalentNote
    {
        $this->assertVisible($actor, $studentId);

        return TalentNote::create([
            'organization_id' => $organization->id,
            'student_id' => $studentId,
            'author_id' => $actor->id,
            'body' => $body,
        ])->fresh(['author:id,name']);
    }

    public function notesFor(Organization $organization, User $actor, int $studentId, int $perPage = 20)
    {
        $this->assertVisible($actor, $studentId);

        return TalentNote::query()
            ->where('organization_id', $organization->id)
            ->where('student_id', $studentId)
            ->with('author:id,name')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function requestContact(
        Organization $organization,
        User $actor,
        int $studentId,
        string $message,
    ): TalentContactRequest {
        $this->assertVisible($actor, $studentId);

        return TalentContactRequest::create([
            'organization_id' => $organization->id,
            'student_id' => $studentId,
            'requested_by' => $actor->id,
            'message' => $message,
            'status' => TalentContactRequest::STATUS_PENDING,
        ])->fresh(['student:id,name', 'requester:id,name']);
    }

    public function broadcastContact(Organization $organization, User $actor, string $message): int
    {
        $studentIds = $this->talent->visibleStudentIds($actor);

        if ($studentIds === []) {
            return 0;
        }

        $now = now();
        $rows = array_map(fn (int $studentId) => [
            'organization_id' => $organization->id,
            'student_id' => $studentId,
            'requested_by' => $actor->id,
            'message' => $message,
            'status' => TalentContactRequest::STATUS_PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ], $studentIds);

        TalentContactRequest::insert($rows);

        return count($rows);
    }

    public function messagesFor(User $student, int $perPage = 20): LengthAwarePaginator
    {
        return TalentContactRequest::query()
            ->where('student_id', $student->id)
            ->with(['organization:id,name', 'requester:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    private function assertVisible(User $actor, int $studentId): void
    {
        try {
            $this->talent->profile($actor, $studentId);
        } catch (NotFoundHttpException) {
            throw new TalentException(
                'No discoverable student with that id.',
                404,
                'TALENT_NOT_FOUND',
                ['student_id' => $studentId],
            );
        }
    }
}
