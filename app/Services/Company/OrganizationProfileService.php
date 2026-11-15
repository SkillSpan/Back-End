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
use Throwable;

class OrganizationProfileService
{
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

    public const READ_ONLY = ['type', 'verification_status', 'verified_at'];

    public function __construct(private readonly DocumentScreeningService $screening) {}

    public function uploadLogo(Organization $organization, User $actor, HttpUploadedFile $file): array
    {
        $maxKb = (int) config('company.profile.logo.max_kb', 2048);

        if ((int) $file->getSize() > $maxKb * 1024) {
            throw new ProfileException(
                "The logo may not be larger than {$maxKb} KB.",
                422,
                'LOGO_TOO_LARGE',
            );
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType();

        if (! in_array($extension, (array) config('company.profile.logo.mimes', []), true)
            || ! in_array($mime, (array) config('company.profile.logo.mime_types', []), true)) {
            throw new ProfileException(
                'The logo must be a PNG, JPEG or WebP image.',
                422,
                'LOGO_TYPE_NOT_SUPPORTED',
            );
        }

        $reason = $this->screening->screen($file);

        if ($reason !== null) {
            throw new ProfileException($reason, 422, 'LOGO_REJECTED_BY_SCAN');
        }

        $disk = $this->logoDisk();
        $path = $file->store('logos/'.$organization->id, $disk === null ? [] : $disk);

        if ($path === false) {
            throw new ProfileException(
                'The logo could not be stored. Please try again in a moment.',
                500,
                'LOGO_STORAGE_FAILED',
            );
        }

        $previous = $organization->logo;

        $organization->forceFill(['logo' => $path])->save();

        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            $this->deleteStoredLogo($previous, $disk);
        }

        $organization->refresh();

        return [
            'logo' => $organization->logo,
            'logo_url' => $this->logoUrl($organization->logo),
            'profile' => $this->payload($organization),
        ];
    }

    public function removeLogo(Organization $organization): array
    {
        $previous = $organization->logo;

        if (is_string($previous) && $previous !== '') {
            $organization->forceFill(['logo' => null])->save();
            $this->deleteStoredLogo($previous, $this->logoDisk());
        }

        $organization->refresh();

        return [
            'logo' => null,
            'logo_url' => null,
            'profile' => $this->payload($organization),
        ];
    }

    private function logoDisk(): ?string
    {
        $disk = config('company.profile.logo.disk');

        return is_string($disk) && $disk !== '' ? $disk : null;
    }

    private function logoUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk($this->logoDisk())->url($path);
    }

    private function deleteStoredLogo(string $path, ?string $disk): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable) {
        }
    }

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
            'logo_url' => $this->logoUrl($organization->logo),

            'verification_status' => $organization->verification_status,
            'verified_at' => $organization->verified_at?->toIso8601String(),

            'reverification' => $this->reverificationState($organization),
            'documents' => $this->documents($organization),

            'profile_completeness' => $completeness['percent'],
            'profile_fields' => $completeness['fields'],
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
            'available' => $this->fileExists($file),
            'url' => '/api/v1/company/profile/documents/'.$file->id,
        ];
    }

    public function update(Organization $organization, User $actor, array $data): array
    {
        $changes = $this->diff($organization, $data);

        if ($changes === []) {
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
                $changes[$column] = ['from' => $current, 'to' => $value];
            }
        }

        return $changes;
    }

    private function verificationRelevantChanges(array $changes): array
    {
        $watched = (array) config('company.profile.reverification.fields', []);

        return array_values(array_intersect($watched, array_keys($changes)));
    }

    private function applyReverificationPolicy(Organization $organization, array $fields): void
    {
        $mode = $this->policyMode();

        if ($mode === 'off') {
            return;
        }

        if ($mode === 'pending') {
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
        } catch (Throwable) {
            return false;
        }
    }
}
