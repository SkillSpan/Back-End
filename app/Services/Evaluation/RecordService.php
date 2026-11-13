<?php

namespace App\Services\Evaluation;

use App\Exceptions\RecordException;
use App\Models\Evaluation;
use App\Models\ProfessionalRecordItem;
use App\Models\ProfessionalRecordSectionVisibility;
use App\Models\ProfessionalRecordShareAccess;
use App\Models\ProfessionalRecordShareLink;
use App\Models\ProfessionalRecordVerification;
use App\Models\Project;
use App\Models\ProjectTeamMember;
use App\Models\SkillEvidence;
use App\Models\StudentProfile;
use App\Models\User;
use App\Traits\AuditsActions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecordService
{
    use AuditsActions;

    public function record(User $student): array
    {
        $profile = $this->profile($student);

        $items = ProfessionalRecordItem::query()
            ->where('student_profile_id', $profile->id)
            ->where('status', ProfessionalRecordItem::STATUS_ACTIVE)
            ->orderByDesc('achieved_at')
            ->orderByDesc('id')
            ->get();

        $visibility = $this->visibilityMap($profile);
        $derived = $this->derivedPayloads($profile, $student);

        $sections = [];
        $total = 0;
        $verified = 0;

        foreach (ProfessionalRecordItem::SECTIONS as $section) {
            $payloads = $items
                ->where('item_type', $section)
                ->map(fn (ProfessionalRecordItem $item) => $this->itemPayload($item))
                ->values()
                ->all();

            if (isset($derived[$section])) {
                $payloads = array_merge($derived[$section], $payloads);
            }

            $total += count($payloads);
            $verified += count(array_filter(
                $payloads,
                fn (array $row) => $row['verification_state'] === ProfessionalRecordItem::VERIFICATION_VERIFIED,
            ));

            $sections[$section] = [
                'visibility' => $visibility[$section] ?? $this->defaultVisibility(),
                'count' => count($payloads),
                'items' => $payloads,
            ];
        }

        return [
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'specialization' => $profile->specialization,
                'academic_level' => $profile->academic_level,
            ],
            'sections' => $sections,
            'total_items' => $total,
            'verified_items' => $verified,
            'share_links' => $this->shareLinks($student)->map(fn (ProfessionalRecordShareLink $link) => $this->shareLinkPayload($link))->values()->all(),
        ];
    }

    private function derivedPayloads(StudentProfile $profile, User $student): array
    {
        $verifiedProjects = Evaluation::query()
            ->where('participant_id', $student->id)
            ->whereIn('status', Evaluation::STUDENT_VISIBLE_STATUSES)
            ->pluck('project_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $projects = ProjectTeamMember::query()
            ->where('user_id', $student->id)
            ->where('assignment_state', 'active')
            ->with(['team.project.organization'])
            ->get()
            ->map(fn (ProjectTeamMember $member) => [$member, $member->team?->project])
            ->filter(fn (array $pair) => $pair[1] instanceof Project)
            ->filter(fn (array $pair) => in_array($pair[1]->status, [
                Project::STATUS_ACTIVE,
                Project::STATUS_UNDER_REVIEW,
                Project::STATUS_COMPLETED,
            ], true))
            ->unique(fn (array $pair) => $pair[1]->id)
            ->map(fn (array $pair) => [
                'id' => null,
                'item_type' => ProfessionalRecordItem::TYPE_PROJECT,
                'title' => $pair[1]->title,
                'description' => $pair[1]->description ?? $pair[1]->objectives,
                'role' => $pair[0]->project_role,
                'contribution' => null,
                'issuer' => $pair[1]->organization?->name ?? 'SkillSpan',
                'project_type' => $pair[1]->type,
                'source_version' => null,
                'supporting_reference' => '/projects/'.$pair[1]->id,
                'verification_state' => in_array((int) $pair[1]->id, $verifiedProjects, true)
                    ? ProfessionalRecordItem::VERIFICATION_VERIFIED
                    : ProfessionalRecordItem::VERIFICATION_SELF_DECLARED,
                'status' => ProfessionalRecordItem::STATUS_ACTIVE,
                'visibility' => $this->defaultVisibility(),
                'achieved_at' => $pair[1]->status === Project::STATUS_COMPLETED && $pair[1]->end_date !== null
                    ? (is_string($pair[1]->end_date) ? $pair[1]->end_date : $pair[1]->end_date->toDateString())
                    : null,
            ])
            ->values()
            ->all();

        $skills = SkillEvidence::query()
            ->where('student_profile_id', $profile->id)
            ->where('verification_status', SkillEvidence::STATUS_VERIFIED)
            ->with('skill')
            ->orderByDesc('evidence_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (SkillEvidence $evidence) => [
                'id' => null,
                'item_type' => ProfessionalRecordItem::TYPE_SKILL,
                'title' => $evidence->skill?->name ?? 'Skill',
                'description' => $evidence->description,
                'role' => null,
                'contribution' => null,
                'issuer' => $evidence->source === 'project_performance' ? 'Project evaluation' : $evidence->source,
                'project_type' => null,
                'source_version' => null,
                'supporting_reference' => $evidence->reference,
                'verification_state' => ProfessionalRecordItem::VERIFICATION_VERIFIED,
                'status' => ProfessionalRecordItem::STATUS_ACTIVE,
                'visibility' => $this->defaultVisibility(),
                'achieved_at' => $evidence->evidence_date === null
                    ? null
                    : (is_string($evidence->evidence_date) ? $evidence->evidence_date : $evidence->evidence_date->toDateString()),
            ])
            ->values()
            ->all();

        return [
            ProfessionalRecordItem::TYPE_PROFILE => [[
                'id' => null,
                'item_type' => ProfessionalRecordItem::TYPE_PROFILE,
                'title' => $student->name,
                'description' => $profile->bio,
                'role' => null,
                'contribution' => null,
                'issuer' => $profile->university_name,
                'project_type' => null,
                'source_version' => null,
                'supporting_reference' => '/profile',
                'verification_state' => ProfessionalRecordItem::VERIFICATION_SELF_DECLARED,
                'status' => ProfessionalRecordItem::STATUS_ACTIVE,
                'visibility' => $this->defaultVisibility(),
                'achieved_at' => null,
            ]],
            ProfessionalRecordItem::TYPE_PROJECT => $projects,
            ProfessionalRecordItem::TYPE_SKILL => $skills,
        ];
    }

    public function updateVisibility(User $student, array $visibility): array
    {
        $profile = $this->profile($student);

        $invalid = array_values(array_diff(array_keys($visibility), ProfessionalRecordItem::SECTIONS));

        if ($invalid !== []) {
            throw new RecordException(
                'One or more record sections are not recognized.',
                422,
                'RECORD_SECTION_INVALID',
                ['sections' => $invalid, 'allowed' => ProfessionalRecordItem::SECTIONS],
            );
        }

        DB::transaction(function () use ($profile, $visibility): void {
            foreach ($visibility as $section => $value) {
                ProfessionalRecordSectionVisibility::updateOrCreate(
                    ['student_profile_id' => $profile->id, 'section' => $section],
                    ['visibility' => $value],
                );
            }
        });

        return $this->visibilityMap($profile->fresh());
    }

    public function share(User $student, array $data, ?string $requestId = null, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $profile = $this->profile($student);

        $sections = $this->normalizeSections((array) ($data['sections'] ?? []));

        $maxDays = (int) config('sprint5.record.share_link_max_days', 180);
        $days = isset($data['days']) ? (int) $data['days'] : (int) config('sprint5.record.share_link_default_days', 30);
        $days = max(1, min($maxDays, $days));

        $token = Str::random(48);

        $link = ProfessionalRecordShareLink::create([
            'student_profile_id' => $profile->id,
            'token_hash' => hash('sha256', $token),
            'sections' => $sections,
            'expires_at' => now()->addDays($days),
        ]);

        $this->audit(
            $student->id,
            'record.share_link_created',
            'professional_record',
            $link->id,
            null,
            ['sections' => $sections, 'days' => $days],
            'professional_record',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        return [
            'token' => $token,
            'url' => '/r/'.$token,
            'expires_at' => $link->expires_at?->toIso8601String(),
            'sections' => $sections,
            'link' => $this->shareLinkPayload($link),
        ];
    }

    public function shareLinks(User $student): Collection
    {
        $profile = $this->profile($student);

        return ProfessionalRecordShareLink::query()
            ->where('student_profile_id', $profile->id)
            ->orderByDesc('id')
            ->get();
    }

    public function revokeShare(User $student, ProfessionalRecordShareLink $link, ?string $requestId = null, ?string $ipAddress = null, ?string $userAgent = null): ProfessionalRecordShareLink
    {
        $profile = $this->profile($student);

        if ((int) $link->student_profile_id !== (int) $profile->id) {
            throw new RecordException(
                'That share link does not belong to your professional record.',
                403,
                'RECORD_SHARE_FORBIDDEN',
                ['share_link_id' => $link->id],
            );
        }

        if ($link->revoked_at === null) {
            $link->forceFill(['revoked_at' => now()])->save();
        }

        $this->audit(
            $student->id,
            'record.share_link_revoked',
            'professional_record',
            $link->id,
            null,
            [],
            'professional_record',
            $requestId,
            $ipAddress,
            $userAgent,
        );

        return $link->fresh();
    }

    public function publicRecord(string $token, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $link = $this->usableLink($token);

        ProfessionalRecordShareAccess::create([
            'share_link_id' => $link->id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'accessed_at' => now(),
        ]);

        $link->forceFill([
            'last_accessed_at' => now(),
            'access_count' => (int) $link->access_count + 1,
        ])->save();

        $profile = $link->studentProfile;

        $sections = $this->normalizeSections((array) $link->sections);

        $items = ProfessionalRecordItem::query()
            ->where('student_profile_id', $link->student_profile_id)
            ->where('status', ProfessionalRecordItem::STATUS_ACTIVE)
            ->whereIn('item_type', $sections)
            ->orderByDesc('achieved_at')
            ->orderByDesc('id')
            ->get();

        $payloads = $items->map(fn (ProfessionalRecordItem $item) => $this->publicItemPayload($item))->values()->all();

        if ($profile !== null && $profile->user !== null) {
            $derived = $this->derivedPayloads($profile, $profile->user);

            foreach ($sections as $section) {
                foreach ($derived[$section] ?? [] as $row) {
                    $payloads[] = [
                        'title' => $row['title'],
                        'description' => $row['description'],
                        'role' => $row['role'],
                        'issuer' => $row['issuer'],
                        'project_type' => $row['project_type'],
                        'verification_state' => $row['verification_state'],
                        'achieved_at' => $row['achieved_at'],
                    ];
                }
            }
        }

        return [
            'student' => [
                'name' => $profile?->user?->name,
                'specialization' => $profile?->specialization,
                'academic_level' => $profile?->academic_level,
            ],
            'sections' => $sections,
            'items' => $payloads,
            'expires_at' => $link->expires_at?->toIso8601String(),
        ];
    }

    public function ensureVerification(ProfessionalRecordItem $item): ProfessionalRecordVerification
    {
        $existing = ProfessionalRecordVerification::query()
            ->where('record_item_id', $item->id)
            ->whereNull('revoked_at')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return ProfessionalRecordVerification::create([
            'reference' => 'SP-'.Str::upper(Str::random(16)),
            'record_item_id' => $item->id,
            'student_profile_id' => $item->student_profile_id,
        ]);
    }

    public function verify(string $reference): array
    {
        $verification = ProfessionalRecordVerification::query()
            ->where('reference', $reference)
            ->with(['item', 'studentProfile.user'])
            ->first();

        if ($verification === null) {
            throw new RecordException(
                'That verification reference does not exist.',
                404,
                'RECORD_VERIFICATION_NOT_FOUND',
                ['reference' => $reference],
            );
        }

        if ($verification->isRevoked()) {
            throw new RecordException(
                'That verification reference has been revoked.',
                410,
                'RECORD_VERIFICATION_REVOKED',
                ['reference' => $reference],
            );
        }

        $verification->forceFill(['access_count' => (int) $verification->access_count + 1])->save();

        $item = $verification->item;

        return [
            'reference' => $verification->reference,
            'verified' => $item !== null && $item->status === ProfessionalRecordItem::STATUS_ACTIVE,
            'student' => [
                'name' => $verification->studentProfile?->user?->name,
            ],
            'item' => $item === null ? null : [
                'title' => $item->title,
                'item_type' => $item->item_type,
                'issuer' => $item->issuer,
                'verification_state' => $item->verification_state,
                'achieved_at' => $item->achieved_at?->toDateString(),
                'status' => $item->status,
            ],
        ];
    }

    public function export(User $student, array $sections = []): array
    {
        $profile = $this->profile($student);
        $selected = $this->normalizeSections($sections);

        $items = ProfessionalRecordItem::query()
            ->where('student_profile_id', $profile->id)
            ->where('status', ProfessionalRecordItem::STATUS_ACTIVE)
            ->whereIn('item_type', $selected)
            ->orderByDesc('achieved_at')
            ->orderByDesc('id')
            ->get();

        $payloads = $items->map(fn (ProfessionalRecordItem $item) => $this->itemPayload($item))->values()->all();

        $derived = $this->derivedPayloads($profile, $student);

        foreach ($selected as $section) {
            $payloads = array_merge($payloads, $derived[$section] ?? []);
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'student' => [
                'name' => $student->name,
                'specialization' => $profile->specialization,
                'academic_level' => $profile->academic_level,
                'university' => $profile->university_name,
            ],
            'sections' => $selected,
            'items' => $payloads,
        ];
    }

    public function renderExportHtml(array $export): string
    {
        $rows = '';

        foreach ($export['items'] as $item) {
            $rows .= '<article class="item">'
                .'<h3>'.e((string) $item['title']).'</h3>'
                .'<p class="meta">'.e((string) $item['item_type'])
                .' &middot; '.e((string) ($item['issuer'] ?? 'SkillSpan'))
                .' &middot; '.e((string) ($item['achieved_at'] ?? ''))
                .' &middot; '.e((string) $item['verification_state'])
                .'</p>'
                .($item['description'] === null ? '' : '<p>'.e((string) $item['description']).'</p>')
                .($item['contribution'] === null ? '' : '<p class="muted">'.e((string) $item['contribution']).'</p>')
                .'</article>';
        }

        if ($rows === '') {
            $rows = '<p class="muted">No record items are available for the selected sections.</p>';
        }

        $student = $export['student'];

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            .'<title>'.e((string) $student['name']).' — SkillSpan professional record</title>'
            .'<style>'
            .'body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:40px;color:#111}'
            .'h1{margin:0 0 4px;font-size:24px}'
            .'h2{font-size:16px;text-transform:uppercase;letter-spacing:.08em;margin:28px 0 8px;color:#555}'
            .'h3{margin:0 0 4px;font-size:16px}'
            .'.meta{font-size:12px;color:#666;margin:0 0 8px}'
            .'.muted{color:#666}'
            .'.item{border-top:1px solid #e5e5e5;padding:12px 0}'
            .'.footer{margin-top:32px;font-size:12px;color:#777}'
            .'</style></head><body>'
            .'<h1>'.e((string) $student['name']).'</h1>'
            .'<p class="meta">'.e((string) ($student['specialization'] ?? '')).'</p>'
            .'<h2>Professional record</h2>'
            .$rows
            .'<p class="footer">Generated '.e((string) $export['generated_at']).' by SkillSpan.</p>'
            .'</body></html>';
    }

    public function itemPayload(ProfessionalRecordItem $item): array
    {
        return [
            'id' => $item->id,
            'item_type' => $item->item_type,
            'title' => $item->title,
            'description' => $item->description,
            'role' => $item->role,
            'contribution' => $item->contribution,
            'issuer' => $item->issuer,
            'project_type' => $item->project_type,
            'source_version' => $item->source_version,
            'supporting_reference' => $item->supporting_reference,
            'verification_state' => $item->verification_state,
            'status' => $item->status,
            'visibility' => $item->visibility,
            'achieved_at' => $item->achieved_at?->toDateString(),
        ];
    }

    public function publicItemPayload(ProfessionalRecordItem $item): array
    {
        return [
            'title' => $item->title,
            'description' => $item->description,
            'role' => $item->role,
            'issuer' => $item->issuer,
            'project_type' => $item->project_type,
            'verification_state' => $item->verification_state,
            'achieved_at' => $item->achieved_at?->toDateString(),
        ];
    }

    public function shareLinkPayload(ProfessionalRecordShareLink $link): array
    {
        return [
            'id' => $link->id,
            'sections' => $link->sections ?? [],
            'expires_at' => $link->expires_at?->toIso8601String(),
            'revoked_at' => $link->revoked_at?->toIso8601String(),
            'last_accessed_at' => $link->last_accessed_at?->toIso8601String(),
            'access_count' => (int) $link->access_count,
            'is_active' => $link->isUsable(),
        ];
    }

    private function profile(User $student): StudentProfile
    {
        $profile = StudentProfile::query()->where('user_id', $student->id)->first();

        if ($profile === null) {
            throw new RecordException(
                'A professional record is only available for learner accounts with a profile.',
                404,
                'RECORD_PROFILE_MISSING',
                ['user_id' => $student->id],
            );
        }

        return $profile;
    }

    private function visibilityMap(StudentProfile $profile): array
    {
        $stored = ProfessionalRecordSectionVisibility::query()
            ->where('student_profile_id', $profile->id)
            ->pluck('visibility', 'section')
            ->all();

        $map = [];

        foreach (ProfessionalRecordItem::SECTIONS as $section) {
            $map[$section] = $stored[$section] ?? $this->defaultVisibility();
        }

        return $map;
    }

    private function defaultVisibility(): string
    {
        $value = (string) config('sprint5.record.default_section_visibility', ProfessionalRecordItem::VISIBILITY_PRIVATE);

        return in_array($value, ProfessionalRecordItem::VISIBILITIES, true)
            ? $value
            : ProfessionalRecordItem::VISIBILITY_PRIVATE;
    }

    private function normalizeSections(array $sections): array
    {
        if ($sections === []) {
            return ProfessionalRecordItem::SECTIONS;
        }

        $invalid = array_values(array_diff($sections, ProfessionalRecordItem::SECTIONS));

        if ($invalid !== []) {
            throw new RecordException(
                'One or more record sections are not recognized.',
                422,
                'RECORD_SECTION_INVALID',
                ['sections' => $invalid, 'allowed' => ProfessionalRecordItem::SECTIONS],
            );
        }

        return array_values(array_unique($sections));
    }

    private function usableLink(string $token): ProfessionalRecordShareLink
    {
        $link = ProfessionalRecordShareLink::query()
            ->where('token_hash', hash('sha256', $token))
            ->with('studentProfile.user')
            ->first();

        if ($link === null) {
            throw new RecordException(
                'That share link does not exist.',
                404,
                'RECORD_SHARE_NOT_FOUND',
            );
        }

        if (! $link->isUsable()) {
            throw new RecordException(
                'That share link is no longer available.',
                410,
                'RECORD_SHARE_UNAVAILABLE',
                [
                    'revoked' => $link->isRevoked(),
                    'expired' => $link->isExpired(),
                ],
            );
        }

        return $link;
    }
}
