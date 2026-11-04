<?php

namespace App\Services\Talent;

use App\Exceptions\TalentException;
use App\Models\Organization;
use App\Models\TalentContactRequest;
use App\Models\TalentList;
use App\Models\TalentListCandidate;
use App\Models\TalentNote;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The company's talent engagement surface: shortlists, notes, comparison and
 * contact requests.
 *
 * WHY THESE LIVE TOGETHER
 * -----------------------
 * All four answer the same question — "what does this company keep about the
 * people it is considering?" — and all four must apply the SAME visibility rule
 * as `GET /talent`. That rule already exists in TalentDiscoveryService, so this
 * class calls `profile()` for every student it is handed rather than re-querying
 * `student_profiles`. A candidate a company cannot see in the list cannot be
 * compared, noted, shortlisted or contacted either — otherwise the list's
 * visibility model would be decorative.
 *
 * SCOPING
 * -------
 * Every read and write is scoped to the organization the controller resolved
 * from the caller's admin membership. A list id from another company is a 404,
 * not a 403: a 403 would confirm the id exists.
 *
 * WHAT IS DELIBERATELY NOT HERE
 * -----------------------------
 * No e-mail is ever read or returned. `requestContact()` records a request and
 * stops there; delivering the learner's address is the learner's decision, and
 * nothing in this class shortcuts it.
 */
class TalentEngagementService
{
    /** A comparison table is a UI affordance, not an export. */
    public const MAX_COMPARE = 5;

    public function __construct(
        private readonly TalentDiscoveryService $talent,
    ) {}

    // -----------------------------------------------------------------
    // Comparison
    // -----------------------------------------------------------------

    /**
     * Compare two to five candidates the caller may actually see.
     *
     * A candidate who is not visible is REPORTED in `unavailable` rather than
     * failing the whole request: a stale shortlist is normal, and losing the
     * comparison because one person made their profile private would be a bad
     * trade. But if fewer than two remain there is nothing to compare, so that
     * is a 422.
     *
     * @param  array<int, int>  $studentIds
     * @return array<string, mixed>
     */
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

    /**
     * One row per skill, one column per student, `null` where the student does
     * not have it.
     *
     * Rows are ordered by how many of the compared students have the skill, so
     * the things they share rise to the top — which is the only ordering that
     * is useful in a comparison table.
     *
     * @param  array<int, array<string, mixed>>  $profiles
     * @return array<int, array<string, mixed>>
     */
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

        // Fill the gaps explicitly, so every row has a value for every student
        // and the client never has to distinguish "missing key" from "no skill".
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

    // -----------------------------------------------------------------
    // Shortlists
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     */
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

    /**
     * A list belonging to this organization, or 404.
     *
     * The organization is part of the WHERE, not a check after the fetch, so a
     * foreign id never loads the row at all.
     */
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

    /**
     * Add a candidate to a list.
     *
     * Idempotent on purpose: a "add to shortlist" button gets pressed twice, and
     * a 422 the second time is a worse experience than the same row coming back.
     * The UNIQUE index is the real guard; `firstOrCreate` is what makes the
     * second press harmless.
     */
    public function addCandidate(
        Organization $organization,
        User $actor,
        int $listId,
        int $studentId,
        ?string $note = null,
    ): array {
        $list = $this->findList($organization, $listId);

        // Same visibility rule as the list endpoint: a company cannot shortlist
        // someone it is not allowed to see.
        $this->assertVisible($actor, $studentId);

        $candidate = TalentListCandidate::query()->firstOrCreate(
            ['talent_list_id' => $list->id, 'student_id' => $studentId],
            ['added_by' => $actor->id, 'note' => $note],
        );

        $alreadyListed = ! $candidate->wasRecentlyCreated;

        // A note supplied on a repeat press is an edit, not a no-op.
        if ($alreadyListed && $note !== null && $note !== $candidate->note) {
            $candidate->note = $note;
            $candidate->save();
        }

        return [
            'candidate' => $candidate->fresh(['student:id,name']),
            'already_listed' => $alreadyListed,
        ];
    }

    // -----------------------------------------------------------------
    // Notes
    // -----------------------------------------------------------------

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

    // -----------------------------------------------------------------
    // Contact
    // -----------------------------------------------------------------

    /**
     * Record a request to contact a candidate.
     *
     * A second pending request to the same person from the same company is
     * refused: the learner already has one unanswered request, and piling more
     * on is not a feature. An ACCEPTED or DECLINED one does not block a new
     * request — circumstances change.
     */
    public function requestContact(
        Organization $organization,
        User $actor,
        int $studentId,
        string $message,
    ): TalentContactRequest {
        $this->assertVisible($actor, $studentId);

        $pending = TalentContactRequest::query()
            ->where('organization_id', $organization->id)
            ->where('student_id', $studentId)
            ->where('status', TalentContactRequest::STATUS_PENDING)
            ->exists();

        if ($pending) {
            throw new TalentException(
                'A contact request to this candidate is already pending.',
                422,
                'TALENT_CONTACT_PENDING',
                ['student_id' => $studentId],
            );
        }

        return TalentContactRequest::create([
            'organization_id' => $organization->id,
            'student_id' => $studentId,
            'requested_by' => $actor->id,
            'message' => $message,
            'status' => TalentContactRequest::STATUS_PENDING,
        ])->fresh(['student:id,name', 'requester:id,name']);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * The student must be discoverable by THIS caller.
     *
     * Delegated to TalentDiscoveryService so there is one visibility model: if
     * `GET /talent` would not show them, nothing here may act on them either.
     * A hidden student is reported as `TALENT_NOT_FOUND` (404), matching what
     * the detail endpoint says — never as a 403, which would confirm the id.
     */
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
