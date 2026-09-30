<?php

namespace App\Services;

use App\Exceptions\CommunicationException;
use App\Models\MentorStudentConnection;
use App\Models\ProfessionalProfile;
use App\Models\Project;
use App\Models\StudentProfile;
use App\Models\User;
use App\Traits\AuditsActions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MentorStudentService
{
    use AuditsActions;

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Get students the mentor is permitted to see.
     * Visibility rules:
     * - Students with an active/pending connection to this mentor
     * - Students with visibility='public'
     * - Students with visibility='organization_only' if mentor shares an org
     */
    public function getPermittedStudents(int $mentorId): Collection
    {
        $connectedStudentIds = MentorStudentConnection::forMentor($mentorId)
            ->active()
            ->pluck('student_id')
            ->toArray();

        $mentorOrgIds = User::find($mentorId)?->organizations()
            ->wherePivot('status', 'active')
            ->pluck('organizations.id')
            ->toArray() ?? [];

        return StudentProfile::with('user')
            ->where(function ($query) use ($connectedStudentIds, $mentorOrgIds) {
                $query->whereIn('user_id', $connectedStudentIds)
                    ->orWhere('visibility', 'public')
                    ->orWhere(function ($q) use ($mentorOrgIds) {
                        $q->where('visibility', 'organization_only')
                            ->whereHas('user.organizations', function ($oq) use ($mentorOrgIds) {
                                $oq->whereIn('organizations.id', $mentorOrgIds);
                            });
                    });
            })
            ->get();
    }

    /**
     * Get a student's summary, enforcing visibility rules.
     */
    public function getStudentSummary(int $mentorId, int $studentId): array
    {
        if (! $this->canAccessStudent($mentorId, $studentId)) {
            throw new CommunicationException(
                'You do not have permission to view this student.',
                403,
                'STUDENT_NOT_VISIBLE',
            );
        }

        $student = User::with('studentProfile')->find($studentId);
        $profile = $student?->studentProfile;

        if (! $profile) {
            throw new CommunicationException(
                'The specified student does not have a student profile.',
                422,
                'STUDENT_PROFILE_NOT_FOUND',
            );
        }

        return [
            'id' => $student->id,
            'name' => $student->name,
            'email' => $student->email,
            'university_name' => $profile->university_name,
            'specialization' => $profile->specialization,
            'career_status' => $profile->career_status,
            'interests' => $profile->interests,
            'availability' => $profile->availability,
            'preferred_work_type' => $profile->preferred_work_type,
            'visibility' => $profile->visibility,
            'completeness_percent' => $profile->completeness_percent,
            'enrollment_status' => $profile->enrollment_status,
        ];
    }

    /**
     * Check if a mentor can access a student's data.
     */
    public function canAccessStudent(int $mentorId, int $studentId): bool
    {
        $hasConnection = MentorStudentConnection::forMentor($mentorId)
            ->where('student_id', $studentId)
            ->active()
            ->exists();

        if ($hasConnection) {
            return true;
        }

        $profile = StudentProfile::where('user_id', $studentId)->first();

        if (! $profile) {
            return false;
        }

        if ($profile->visibility === 'public') {
            return true;
        }

        if ($profile->visibility === 'organization_only') {
            $mentorOrgIds = User::find($mentorId)?->organizations()
                ->wherePivot('status', 'active')
                ->pluck('organizations.id')
                ->toArray() ?? [];

            $studentOrgIds = User::find($studentId)?->organizations()
                ->wherePivot('status', 'active')
                ->pluck('organizations.id')
                ->toArray() ?? [];

            return count(array_intersect($mentorOrgIds, $studentOrgIds)) > 0;
        }

        return false;
    }

    /**
     * Create a mentor-student connection with full validation.
     */
    public function createConnection(
        int $mentorId,
        int $studentId,
        ?int $projectId,
        string $initiatedBy = 'mentor',
        ?Request $request = null,
    ): MentorStudentConnection {
        $isMentor = ProfessionalProfile::where('user_id', $mentorId)
            ->where('type', 'mentor')
            ->where('verification_status', 'verified')
            ->exists();

        if (! $isMentor) {
            throw new CommunicationException(
                'Only verified mentors can create connections.',
                403,
                'MENTOR_ONLY',
            );
        }

        $student = User::find($studentId);
        if (! $student || ! $student->studentProfile) {
            throw new CommunicationException(
                'The specified student does not have a student profile.',
                422,
                'STUDENT_PROFILE_NOT_FOUND',
            );
        }

        // Duplicate check — handles the unique constraint gracefully.
        $existing = MentorStudentConnection::forMentor($mentorId)
            ->where('student_id', $studentId)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->when(! $projectId, fn ($q) => $q->whereNull('project_id'))
            ->exists();

        if ($existing) {
            throw new CommunicationException(
                'A connection already exists between this mentor and student'
                .($projectId ? ' for this project.' : '.'),
                422,
                'DUPLICATE_CONNECTION',
            );
        }

        $project = null;
        if ($projectId) {
            $project = Project::find($projectId);
            if (! $project) {
                throw new CommunicationException(
                    'The specified project does not exist.',
                    404,
                    'PROJECT_NOT_FOUND',
                );
            }

            $this->validateProjectAvailability($project);
            $this->validateProjectEligibility($project, $studentId);
        }

        $requestId = $request
            ? (string) ($request->header('X-Request-ID') ?: Str::uuid())
            : Str::uuid()->toString();

        DB::beginTransaction();
        try {
            $connection = MentorStudentConnection::create([
                'mentor_id' => $mentorId,
                'student_id' => $studentId,
                'project_id' => $projectId,
                'status' => 'pending',
                'initiated_by' => $initiatedBy,
            ]);

            $this->audit(
                actorId: $mentorId,
                action: 'mentor_student_connection.created',
                entityType: 'MentorStudentConnection',
                entityId: $connection->id,
                after: $connection->toArray(),
                purpose: 'Mentor initiated a connection with a student'
                    .($project ? " for project {$project->title}" : ''),
                requestId: $requestId,
                ipAddress: $request?->ip(),
                userAgent: $request?->userAgent(),
            );

            // In-app notification to the student, honouring their
            // per-category notification preferences.
            $this->notifications->dispatch(
                userId: $studentId,
                category: 'mentor_connection',
                title: 'New mentor connection request',
                body: User::find($mentorId)?->name
                    .' wants to connect with you'
                    .($project ? " for project: {$project->title}" : '').'.',
                link: '/mentor/connections/'.$connection->id,
                eventKey: 'mentor_connection:'.$connection->id,
            );

            DB::commit();

            return $connection->fresh();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Validate project availability for new connections.
     */
    public function validateProjectAvailability(Project $project): void
    {
        if (! in_array($project->status, [Project::STATUS_OPEN, Project::STATUS_ACTIVE], true)) {
            throw new CommunicationException(
                "Project is not available for connections (status: {$project->status}).",
                422,
                'PROJECT_NOT_AVAILABLE',
            );
        }

        if ($project->application_deadline
            && $project->application_deadline < now()->toDateString()) {
            throw new CommunicationException(
                'Project application deadline has passed.',
                422,
                'PROJECT_DEADLINE_PASSED',
            );
        }

        if ($project->capacity) {
            $activeConnections = MentorStudentConnection::where('project_id', $project->id)
                ->whereIn('status', ['pending', 'active'])
                ->count();

            if ($activeConnections >= $project->capacity) {
                throw new CommunicationException(
                    'Project has reached its capacity.',
                    422,
                    'PROJECT_CAPACITY_REACHED',
                );
            }
        }
    }

    /**
     * Validate project eligibility rules against student profile.
     */
    public function validateProjectEligibility(Project $project, int $studentId): void
    {
        $constraints = $project->eligibilityConstraints;

        if ($constraints->isEmpty()) {
            return;
        }

        $profile = StudentProfile::where('user_id', $studentId)->first();
        $user = User::find($studentId);

        $violations = [];

        foreach ($constraints as $constraint) {
            $value = $constraint->value;

            switch ($constraint->constraint_type) {
                case 'work_mode':
                    if ($profile?->preferred_work_type
                        && $profile->preferred_work_type !== $value) {
                        $violations[] = "Work mode mismatch: project requires '{$value}', student prefers '{$profile->preferred_work_type}'.";
                    }
                    break;

                case 'schedule':
                    if ($profile?->availability
                        && $profile->availability !== $value) {
                        $violations[] = "Schedule mismatch: project requires '{$value}', student availability is '{$profile->availability}'.";
                    }
                    break;

                case 'language':
                    if ($user?->locale && $user->locale !== $value) {
                        $violations[] = "Language mismatch: project requires '{$value}', student locale is '{$user->locale}'.";
                    }
                    break;

                    // location: no direct field on StudentProfile; skip.
                case 'location':
                    break;
            }
        }

        if (! empty($violations)) {
            throw new CommunicationException(
                'Student does not meet project eligibility requirements.',
                422,
                'PROJECT_ELIGIBILITY_FAILED',
                ['violations' => $violations],
            );
        }
    }

    /**
     * Update connection status (accept, disconnect, archive).
     */
    public function updateConnectionStatus(
        int $connectionId,
        string $status,
        ?string $reason = null,
        ?int $actorId = null,
        ?Request $request = null,
    ): MentorStudentConnection {
        $connection = MentorStudentConnection::findOrFail($connectionId);
        $before = $connection->toArray();

        $updateData = ['status' => $status];

        if ($status === 'disconnected') {
            $updateData['disconnected_reason'] = $reason;
            $updateData['disconnected_at'] = now();
        }

        $connection->update($updateData);
        $connection->refresh();

        $requestId = $request
            ? (string) ($request->header('X-Request-ID') ?: Str::uuid())
            : Str::uuid()->toString();

        $this->audit(
            actorId: $actorId,
            action: "mentor_student_connection.{$status}",
            entityType: 'MentorStudentConnection',
            entityId: $connection->id,
            before: $before,
            after: $connection->toArray(),
            purpose: $reason,
            requestId: $requestId,
            ipAddress: $request?->ip(),
            userAgent: $request?->userAgent(),
        );

        // Notify the other party about status changes, honouring their
        // per-category notification preferences.
        if (in_array($status, ['active', 'disconnected'])) {
            $recipientId = $actorId === $connection->mentor_id
                ? $connection->student_id
                : $connection->mentor_id;

            $this->notifications->dispatch(
                userId: $recipientId,
                category: 'mentor_connection',
                title: $status === 'active'
                    ? 'Mentor connection accepted'
                    : 'Mentor connection disconnected',
                body: $reason ?? 'Connection status updated.',
                link: '/mentor/connections/'.$connection->id,
                eventKey: "mentor_connection_{$status}:{$connection->id}",
            );
        }

        return $connection;
    }

    /**
     * Get connections for a mentor.
     */
    public function getMentorConnections(int $mentorId)
    {
        return MentorStudentConnection::forMentor($mentorId)
            ->with(['student.studentProfile', 'project'])
            ->latest()
            ->paginate(20);
    }
}
