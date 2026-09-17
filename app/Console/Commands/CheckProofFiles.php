<?php

namespace App\Console\Commands;

use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Answers one question: are the organization proof documents actually
 * reachable right now?
 *
 * It runs exactly the check Admin\OrganizationController::downloadProofFile
 * performs before serving a file — a row in the database plus a file on the
 * configured disk — so "PRESENT" here means the panel's download link will
 * work, and "MISSING" means it will 404.
 *
 * Worth running after every deploy: a container filesystem without a
 * persistent volume silently discards uploads, and the database rows stay
 * behind, so the failure is invisible until someone opens a proof.
 */
class CheckProofFiles extends Command
{
    protected $signature = 'admin:check-proofs {--missing-only : Only list the documents that are missing}';

    protected $description = 'Check whether organization proof documents are reachable on the configured disk';

    public function handle(): int
    {
        $disk = config('filesystems.default');
        $driver = config("filesystems.disks.{$disk}.driver");
        $location = config("filesystems.disks.{$disk}.root")
            ?? config("filesystems.disks.{$disk}.bucket")
            ?? '(not set)';

        $this->newLine();
        $this->line("  Disk  <info>{$disk}</info> ({$driver})");
        $this->line("  Root  {$location}");
        $this->line('  Set by FILESYSTEM_DISK.');
        $this->newLine();

        // Deliberately uses the model's own proofFile() rather than re-deriving
        // the rule here: if the resolution logic ever changes, this check has
        // to change with it or it starts lying about the download route.
        // One query per organization is fine at this scale.
        $organizations = Organization::all();

        $rows = [];
        $present = 0;
        $missing = 0;
        $noProof = 0;

        foreach ($organizations as $organization) {
            $file = $organization->proofFile();

            if (! $file) {
                $noProof++;

                continue;
            }

            // The same test the download route runs.
            $exists = Storage::exists($file->path);

            $exists ? $present++ : $missing++;

            $rows[] = [
                $organization->id,
                mb_strimwidth($organization->name, 0, 24, '…'),
                $file->mime_type,
                $file->path,
                $exists ? '<info>PRESENT</info>' : '<error>MISSING</error>',
                $exists,
            ];
        }

        $visible = $this->option('missing-only')
            ? array_filter($rows, fn ($row) => ! $row[5])
            : $rows;

        if ($visible !== []) {
            $this->table(
                ['Org', 'Name', 'Type', 'Path', 'On disk'],
                array_map(fn ($row) => array_slice($row, 0, 5), $visible)
            );
        }

        $this->line(sprintf(
            '  %d organizations · %d with a proof document · %d present · %d missing · %d with none submitted',
            $organizations->count(),
            $present + $missing,
            $present,
            $missing,
            $noProof
        ));
        $this->newLine();

        if ($missing > 0) {
            $this->error("{$missing} proof document(s) cannot be served — the panel will report \"Proof file not found.\"");
            $this->line('  The database row survived but the file did not. That normally means the');
            $this->line("  '{$disk}' disk is not persistent (a container filesystem is wiped on every deploy).");
            $this->line('  See PROOF_FILES_STORAGE_FIX.md — the files themselves are not recoverable.');

            return self::FAILURE;
        }

        if ($present === 0) {
            $this->warn('No organization has submitted a proof document yet, so there is nothing to check.');

            return self::SUCCESS;
        }

        $this->info("All {$present} proof document(s) are reachable on the '{$disk}' disk.");

        return self::SUCCESS;
    }
}
