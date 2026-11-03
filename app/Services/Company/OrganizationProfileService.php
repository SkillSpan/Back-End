<?php

namespace App\Services\Company;

use App\Exceptions\ProfileException;
use App\Models\Organization;
use App\Models\UploadedFile;
use App\Models\User;
use App\Services\Screening\DocumentScreeningService;
use Illuminate\Http\UploadedFile as HttpUploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * US-COMP-04 — the organization profile: what it returns, what may change, and
 * what a change costs.
 *
 * Three rules shape this class:
 *
 *   1. **Completeness is configuration, not code** (BR-08). The field list
 *      lives in `config/company.php`, and the version that produced a stored
 *      percentage is stored beside it, so an old snapshot stays readable after
 *      the list changes.
 *   2. **Documents are versioned, never overwritten** (Scenario 4). A
 *      replacement inserts a row that points at the one it supersedes; the
 *      previous row stays for the audit trail.
 *   3. **A verification-relevant change is a policy decision** (BR-09), so the
 *      policy is a config value with three documented modes rather than an
 *      `if` hidden in the update path.
 *
 * Verification status is never written by an update unless the policy says so
 * (BR-03): `verification_status` and `type` are not in EDITABLE, and a request
 * that tries to set them is rejected with FIELD_NOT_EDITABLE rather than
 * silently ignored — a client that believes it changed something is worse than
 * one that gets an error.
 */
class OrganizationProfileService
{
    /**
     * The columns a representative may change (BR-02 / BR-03).
     *
     * `type` is deliberately absent: BR-02 forbids a company user from editing
     * fields owned by another role type, and moving `company` to `university`
     * or `training_partner` is exactly that. `verification_status`,
     * `verified_at` and `verified_by` belong to the review workflow.
     */
    public const EDITABLE = [
        'name',
        'industry',
        'company_size',
        'website',
        'description',
        'country',
        'city',
        'address',
        'postal_code',
        'contact_email',
        'contact_phone',
    ];

    /** Read-only in the payload, and rejected if a request tries to send them. */
    public const READ_ONLY = ['type', 'verification_status', 'verified_at'];

    public function __construct(private readonly DocumentScreeningService $screening) {}

    // -----------------------------------------------------------------
    // Read
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function payload(Organization $organization): array
    {
        $completeness = $this->completeness($organization);

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'type' => $organization->type,
            'industry' => $organization->industry,
            'company_size' => $organization->company_size,
            'website' => $organization->website,
            'description' => $organization->description,
            'country' => $organization->country,
            'city' => $organization->city,
            'address' => $organization->address,
            'postal_code' => $organization->postal_code,
            'contact_email' => $organization->contact_email,
            'contact_phone' => $organization->contact_phone,
            'logo' => $organization->logo,

            // BR-03 — read-only, and the client renders them as such.
            'verification_status' => $organization->verification_status,
            'verified_at' => $organization->verified_at?->toIso8601String(),

            'reverification' => $this->reverificationState($organization),
            'documents' => $this->documents($organization),

            // The flat keys the dashboard already reads (US-COMP-03) …
            'profile_completeness' => $completeness['percent'],
            'profile_fields' => $completeness['fields'],
            // … and the richer object the profile screen asked for.
            'completeness' => [
                'percent' => $completeness['percent'],
                'version' => $completeness['version'],
                'missing' => $completeness['missing'],
            ],

            'profile_updated_at' => $organization->profile_updated_at?->toIso8601String(),
            'profile_updated_by' => $organization->profile_updated_by,
            'updated_at' => $organization->updated_at?->toIso8601String(),
        ];
    }

    /**
     * BR-08 / AC-09 — percentage plus the missing fields, computed from the
     * configured groups. Every group is weighted equally: a company that has
     * filled four of six groups reads 67 %, which is what the progress bar
     * shows, and what the stored snapshot records.
     *
     * @return array{percent: int, version: string, fields: array<int, array{key: string, level: string, done: bool}>, missing: array<int, array{field: string, level: string}>}
     */
    public function completeness(Organization $organization): array
    {
        $fields = [];
        $missing = [];
        $done = 0;

        foreach ((array) config('company.profile.fields', []) as $definition) {
            $key = (string) ($definition['key'] ?? '');
            $level = (string) ($definition['level'] ?? 'required');

            if ($key === '') {
                continue;
            }

            $isDone = $this->groupIsDone($organization, $definition);

            $fields[] = ['key' => $key, 'level' => $level, 'done' => $isDone];

            if ($isDone) {
                $done++;
            } else {
                $missing[] = ['field' => $key, 'level' => $level];
            }
        }

        $total = count($fields);

        return [
            'percent' => $total === 0 ? 0 : (int) round($done / $total * 100),
            'version' => (string) config('company.profile.completeness_version', 'v1'),
            'fields' => $fields,
            'missing' => $missing,
        ];
    }

    /**
     * The current documents, one slot per configured type.
     *
     * A slot is `null` when nothing was ever uploaded. When a document was
     * replaced, the newest row wins and the older versions are reachable only
     * through the audit trail — which is what "the previous document reference
     * remains in the audit history" means.
     *
     * @return array<string, array<string, mixed>|null>
     */
    public function documents(Organization $organization): array
    {
        $allowed = (array) config('company.profile.documents.allowed', []);
        $storedToPublic = [];

        foreach ($allowed as $public => $definition) {
            $storedToPublic[(string) ($definition['stored_as'] ?? '')] = (string) $public;
        }

        $latest = [];

        foreach ($organization->files()->orderByDesc('id')->get() as $file) {
            $public = $storedToPublic[(string) $file->type] ?? null;

            if ($public === null || array_key_exists($public, $latest)) {
                continue;
            }

            $latest[$public] = $file;
        }

        $out = [];

        foreach (array_keys($allowed) as $public) {
            $out[$public] = isset($latest[$public]) ? $this->documentPayload($latest[$public]) : null;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function documentPayload(UploadedFile $file): array
    {
        return [
            'id' => $file->id,
            'type' => $this->publicTypeFor((string) $file->type),
            'name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'size' => $file->size === null ? null : (int) $file->size,
            'version' => (int) ($file->version ?? 1),
            'status' => $file->status,
            'uploaded_at' => $file->created_at?->toIso8601String(),
            // A row can outlive its bytes (a container filesystem without a
            // persistent volume drops uploads on every deploy). Saying so is
            // better than handing the client a URL that 404s.
            'available' => $this->fileExists($file),
            'url' => '/api/v1/company/profile/documents/'.$file->id,
        ];
    }

    // -----------------------------------------------------------------
    // Update
    // -----------------------------------------------------------------

    /**
     * Apply a partial update.
     *
     * @param  array<string, mixed>  $data  already validated
     * @return array{changed: array<string, array{from: mixed, to: mixed}>, reverification_triggered: bool, reverification: array<string, mixed>, profile: array<string, mixed>}
     */
    public function update(Organization $organization, User $actor, array $data): array
    {
        $changes = $this->diff($organization, $data);

        if ($changes === []) {
            // Nothing to write, nothing to audit: a PATCH that changes no value
            // is a no-op, not a profile edit.
            return [
                'changed' => [],
                'reverification_triggered' => false,
                'reverification' => $this->reverificationState($organization),
                'profile' => $this->payload($organization),
            ];
        }

        $verificationRelevant = $this->verificationRelevantChanges($changes);

        DB::transaction(function () use ($organization, $actor, $changes, $verificationRelevant) {
            $organization->forceFill(array_map(static fn (array $change) => $change['to'], $changes));

            $completeness = $this->completeness($organization);

            $organization->forceFill([
                'profile_updated_at' => now(),
                'profile_updated_by' => $actor->id,
                'profile_completeness' => $completeness['percent'],
                'completeness_version' => $completeness['version'],
            ])->save();

            if ($verificationRelevant !== []) {
                $this->applyReverificationPolicy($organization, $verificationRelevant);
            }
        });

        $organization->refresh();

        return [
            'changed' => $changes,
            'reverification_triggered' => $verificationRelevant !== [] && $this->policyMode() !== 'off',
            'reverification' => $this->reverificationState($organization),
            'profile' => $this->payload($organization),
        ];
    }

    /**
     * Reject a payload that tries to change a read-only column.
     *
     * Only an actual *change* is refused. A client that echoes the whole
     * object back — the common case when a form is seeded from the GET — sends
     * `type: "company"` and `verification_status: "verified"` on every save;
     * failing those would make the endpoint unusable for the very client it
     * was written for. Sending a *different* value is a different matter: that
     * is an attempt to edit something SkillSpan owns (BR-02 / BR-03).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ProfileException
     */
    public function guardReadOnly(Organization $organization, array $data): void
    {
        $attempted = [];

        foreach (self::READ_ONLY as $column) {
            if (! array_key_exists($column, $data)) {
                continue;
            }

            $current = $organization->getAttribute($column);

            if (is_string($current) && is_string($data[$column])) {
                if (trim($current) === trim($data[$column])) {
                    continue;
                }
            } elseif ($current === $data[$column]) {
                continue;
            }

            $attempted[] = $column;
        }

        if ($attempted === []) {
            return;
        }

        throw new ProfileException(
            'These fields are managed by SkillSpan and cannot be edited: '.implode(', ', $attempted).'.',
            422,
            'FIELD_NOT_EDITABLE',
            ['fields' => $attempted],
        );
    }

    /**
     * BR-04 — the contact group is the one place where a partial update can
     * make a required group *disappear*: clearing the e-mail is fine while a
     * phone remains, clearing both leaves the organization unreachable.
     *
     * The other required groups (`info`, `address`) have a column the company
     * cannot empty (`name`) or that only lowers completeness when removed, so
     * they are not blocked here — an organization that never filled its city
     * must still be able to edit its description.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ProfileException
     */
    public function guardRequiredContact(Organization $organization, array $data): void
    {
        $touchesContact = array_key_exists('contact_email', $data) || array_key_exists('contact_phone', $data);

        if (! $touchesContact) {
            return;
        }

        $email = array_key_exists('contact_email', $data) ? $data['contact_email'] : $organization->contact_email;
        $phone = array_key_exists('contact_phone', $data) ? $data['contact_phone'] : $organization->contact_phone;

        if ($this->hasValue($email) || $this->hasValue($phone)) {
            return;
        }

        throw new ProfileException(
            'An organization needs at least one way to be reached: keep a contact e-mail or a contact phone.',
            422,
            'PROFILE_CONTACT_REQUIRED',
            ['fields' => ['contact_email', 'contact_phone']],
        );
    }

    // -----------------------------------------------------------------
    // Documents
    // -----------------------------------------------------------------

    /**
     * Upload a new document, or replace the current one of the same type.
     *
     * @return array{document: array<string, mixed>, replaced: array<string, mixed>|null, reverification_triggered: bool, reverification: array<string, mixed>, profile: array<string, mixed>}
     *
     * @throws ProfileException
     */
    public function uploadDocument(
        Organization $organization,
        User $actor,
        string $type,
        HttpUploadedFile $file,
    ): array {
        $definition = $this->documentDefinition($type);

        $reason = $this->screening->screen($file);

        if ($reason !== null) {
            throw new ProfileException(
                $reason,
                422,
                'DOCUMENT_REJECTED_BY_SCAN',
            );
        }

        $storedAs = (string) $definition['stored_as'];

        $previous = $organization->files()
            ->where('type', $storedAs)
            ->orderByDesc('id')
            ->first();

        // `store()` treats its second argument as an options array, so a null
        // disk has to be expressed as "no options" rather than as null — the
        // default disk then applies, which is what an unset
        // COMPANY_DOCUMENT_DISK means.
        $disk = $this->disk();
        $path = $file->store('documents/'.$organization->id, $disk === null ? [] : $disk);

        if ($path === false) {
            throw new ProfileException(
                'The document could not be stored. Please try again in a moment.',
                500,
                'DOCUMENT_STORAGE_FAILED',
            );
        }

        $document = DB::transaction(function () use ($organization, $actor, $file, $path, $storedAs, $previous) {
            return UploadedFile::forceCreate([
                'user_id' => $actor->id,
                'fileable_type' => Organization::class,
                'fileable_id' => $organization->id,
                'type' => $storedAs,
                'version' => $previous === null ? 1 : ((int) ($previous->version ?? 1)) + 1,
                'replaces_id' => $previous?->id,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                // A replacement is a new document and needs a new review;
                // nothing about the previous approval carries over.
                'status' => 'pending',
                'screened_at' => now(),
            ]);
        });

        $verificationRelevant = in_array($type, (array) config('company.profile.reverification.documents', []), true);

        if ($verificationRelevant) {
            DB::transaction(fn () => $this->applyReverificationPolicy($organization, [$type]));
        }

        $organization->refresh();

        return [
            'document' => $this->documentPayload($document),
            'replaced' => $previous === null ? null : $this->documentPayload($previous),
            'reverification_triggered' => $verificationRelevant && $this->policyMode() !== 'off',
            'reverification' => $this->reverificationState($organization),
            'profile' => $this->payload($organization),
        ];
    }

    /**
     * Resolve a document the caller is allowed to read, or null.
     *
     * The lookup goes through the organization's own `files()` relation, so a
     * document belonging to another organization is not "forbidden" — it does
     * not exist as far as this caller is concerned (BR-07 / AC-08).
     */
    public function findDocument(Organization $organization, int $documentId): ?UploadedFile
    {
        $allowed = array_map(
            static fn (array $definition) => (string) ($definition['stored_as'] ?? ''),
            array_values((array) config('company.profile.documents.allowed', [])),
        );

        return $organization->files()
            ->whereKey($documentId)
            ->whereIn('type', $allowed)
            ->first();
    }

    /**
     * @throws ProfileException
     */
    public function documentDefinition(string $type): array
    {
        $allowed = (array) config('company.profile.documents.allowed', []);

        if (! isset($allowed[$type])) {
            throw new ProfileException(
                'Unknown document type. Use one of: '.implode(', ', array_keys($allowed)).'.',
                422,
                'DOCUMENT_TYPE_UNKNOWN',
                ['allowed' => array_keys($allowed)],
            );
        }

        return (array) $allowed[$type];
    }

    public function disk(): ?string
    {
        $disk = config('company.profile.documents.disk');

        return is_string($disk) && $disk !== '' ? $disk : null;
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function diff(Organization $organization, array $data): array
    {
        $changes = [];

        foreach (self::EDITABLE as $column) {
            if (! array_key_exists($column, $data)) {
                continue;
            }

            $value = is_string($data[$column]) ? trim($data[$column]) : $data[$column];

            if ($value === '') {
                $value = null;
            }

            $current = $organization->getAttribute($column);

            if ($current !== $value) {
                // Both sides are kept: the audit row has to say what the
                // value was, and "who changed the company name, and from
                // what" cannot be answered from the new value alone.
                $changes[$column] = ['from' => $current, 'to' => $value];
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<int, string>
     */
    private function verificationRelevantChanges(array $changes): array
    {
        $watched = (array) config('company.profile.reverification.fields', []);

        return array_values(array_intersect($watched, array_keys($changes)));
    }

    /**
     * BR-09 — apply the configured policy to a verification-relevant change.
     *
     * @param  array<int, string>  $fields
     */
    private function applyReverificationPolicy(Organization $organization, array $fields): void
    {
        $mode = $this->policyMode();

        if ($mode === 'off') {
            return;
        }

        if ($mode === 'pending') {
            // The strict reading: the organization goes back into the review
            // queue, which also removes its access to the company workspace
            // until an administrator approves it again. Documented, not
            // accidental — `flag` is the default for this reason.
            $organization->forceFill([
                'verification_status' => 'pending',
                'verified_at' => null,
                'verified_by' => null,
                'reverification_required_at' => now(),
            ])->save();

            return;
        }

        if ($organization->reverification_required_at === null) {
            $organization->forceFill(['reverification_required_at' => now()])->save();
        }
    }

    private function policyMode(): string
    {
        $mode = (string) config('company.profile.reverification.mode', 'flag');

        return in_array($mode, ['flag', 'pending', 'off'], true) ? $mode : 'flag';
    }

    /**
     * @return array<string, mixed>
     */
    private function reverificationState(Organization $organization): array
    {
        $required = $organization->reverification_required_at !== null;

        return [
            'required' => $required,
            'requested_at' => $organization->reverification_required_at?->toIso8601String(),
            'policy' => $this->policyMode(),
            'fields' => array_values((array) config('company.profile.reverification.fields', [])),
            'message' => $required
                ? 'A field that identifies your organization changed, so its verification is being reviewed again.'
                : null,
        ];
    }

    private function groupIsDone(Organization $organization, array $definition): bool
    {
        $columns = (array) ($definition['columns'] ?? []);

        if ($columns === []) {
            return false;
        }

        $present = array_map(
            fn (string $column) => $this->hasValue($organization->getAttribute($column)),
            $columns,
        );

        return ($definition['mode'] ?? 'all') === 'any'
            ? in_array(true, $present, true)
            : ! in_array(false, $present, true);
    }

    private function hasValue(mixed $value): bool
    {
        return is_string($value) ? trim($value) !== '' : $value !== null;
    }

    private function publicTypeFor(string $storedAs): ?string
    {
        foreach ((array) config('company.profile.documents.allowed', []) as $public => $definition) {
            if ((string) ($definition['stored_as'] ?? '') === $storedAs) {
                return (string) $public;
            }
        }

        return null;
    }

    private function fileExists(UploadedFile $file): bool
    {
        try {
            return Storage::disk($this->disk())->exists((string) $file->path);
        } catch (\Throwable) {
            // A misconfigured disk must not turn a profile read into a 500.
            return false;
        }
    }
}
