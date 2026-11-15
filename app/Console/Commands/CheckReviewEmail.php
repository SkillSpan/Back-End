<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationApprovedNotification;
use App\Notifications\OrganizationRejectedNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

class CheckReviewEmail extends Command
{
    protected $signature = 'admin:check-review-email
        {organization : The id of the organization to inspect}
        {--send-to= : Actually dispatch the approval email to this address}';

    protected $description = 'Show why an organization approval email would or would not be sent';

    public function handle(): int
    {
        $organization = Organization::find($this->argument('organization'));

        if (! $organization) {
            $this->error("No organization with id {$this->argument('organization')}.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->line(sprintf(
            '  Organization <info>%d</info> — %s (%s)',
            $organization->id,
            $organization->name,
            $organization->type
        ));
        $this->line("  verification_status  <info>{$organization->verification_status}</info>");
        $this->newLine();

        $blocked = $this->reportProofDocument($organization);
        $recipients = $this->reportRecipients($organization);
        $this->reportMailer();
        $this->reportTemplates($organization, $recipients);

        if ($this->option('send-to')) {
            $this->sendRealEmail($organization, $this->option('send-to'));
        }

        if ($blocked) {
            $this->newLine();
            $this->error('Approval would be REFUSED before any email is sent — see the proof document above.');
            $this->line('  Rejecting the same organization would still send its email, which is exactly');
            $this->line('  the "rejection arrives, approval does not" symptom.');

            return self::FAILURE;
        }

        if ($recipients === []) {
            $this->newLine();
            $this->error('No active admin account to notify — the email would be dispatched to nobody.');
            $this->line('  The organization needs an organization_members row with role_in_org=admin');
            $this->line('  and status=active, on a user account that has an email address.');

            return self::FAILURE;
        }

        if ($organization->verification_status !== 'pending') {
            $this->newLine();
            $this->warn(sprintf(
                'This organization is already %s, so approving it again returns 422 and sends nothing.',
                $organization->verification_status
            ));
        }

        $this->newLine();
        $this->info('Approval would proceed and notify: '.implode(', ', $recipients));

        return self::SUCCESS;
    }

    private function reportProofDocument(Organization $organization): bool
    {
        $proof = $organization->proofFile();

        if (! $proof) {
            $this->line('  Proof document       <error>MISSING</error>');

            return true;
        }

        $this->line(sprintf(
            '  Proof document       <info>PRESENT</info> (type=%s, status=%s)',
            $proof->type,
            $proof->status
        ));

        return false;
    }

    private function reportRecipients(Organization $organization): array
    {
        $members = $organization->members()->wherePivot('role_in_org', 'admin')->get();
        $recipients = [];

        $this->newLine();
        $this->line('  Admin memberships (organization_members.role_in_org = admin):');

        if ($members->isEmpty()) {
            $this->line('    <error>none</error>');

            return [];
        }

        foreach ($members as $member) {
            $address = $member->email ?: '(no email on the account)';

            if ($member->pivot->status !== 'active') {
                $this->line("    <comment>SKIPPED</comment> {$address} — membership {$member->pivot->status}");

                continue;
            }

            if (! $member->email) {
                $this->line("    <comment>SKIPPED</comment> #{$member->id} — account has no email address");

                continue;
            }

            $this->line("    <info>NOTIFIED</info> {$address}");
            $recipients[] = $member->email;
        }

        return $recipients;
    }

    private function reportMailer(): void
    {
        $mailer = config('mail.default');
        $from = config('mail.from.address');

        $this->newLine();
        $this->line("  Mailer               <info>{$mailer}</info>");
        $this->line("  From                 {$from}");

        if ($mailer === 'log') {
            $this->line('    <comment>MAIL_MAILER=log writes the message to storage/logs/laravel.log —</comment>');
            $this->line('    <comment>nothing is delivered to any inbox in this configuration.</comment>');
        }
    }

    private function reportTemplates(Organization $organization, array $recipients): void
    {
        $this->newLine();
        $this->line('  Templates:');

        $notifiable = $organization->members()->wherePivot('role_in_org', 'admin')->first()
            ?? new User(['name' => 'SkillSpan Admin', 'email' => $recipients[0] ?? 'noreply@example.com']);

        $messages = [
            'emails.organization-approved' => new OrganizationApprovedNotification($organization),
            'emails.organization-rejected' => new OrganizationRejectedNotification($organization, 'Sample reason.'),
        ];

        foreach ($messages as $label => $notification) {
            try {
                $notification->toMail($notifiable)->render();
                $this->line("    <info>OK</info>     {$label}");
            } catch (Throwable $e) {
                $this->line("    <error>FAILS</error>  {$label}");
                $this->line('           '.$e->getMessage());
                $this->line('           This is what would abort the notification in production.');
            }
        }
    }

    private function sendRealEmail(Organization $organization, string $address): void
    {
        $this->newLine();
        $this->line("  Sending a real approval email to <info>{$address}</info> via ".config('mail.default').' …');

        try {
            Notification::route('mail', $address)
                ->notify(new OrganizationApprovedNotification($organization));

            $this->info("  Dispatched. Check {$address} — and the application log for the send result.");
        } catch (Throwable $e) {
            $this->error('  Failed: '.$e->getMessage());
        }
    }
}
