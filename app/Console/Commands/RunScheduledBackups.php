<?php

namespace App\Console\Commands;

use App\Jobs\ProcessBackupJob;
use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Services\Backup\BackupJobFactory;
use Illuminate\Console\Command;

class RunScheduledBackups extends Command
{
    protected $signature = 'backups:run {schedule : The backup schedule ID to run}';

    protected $description = 'Run scheduled backups for a given backup schedule';

    public function handle(BackupJobFactory $backupJobFactory): int
    {
        $scheduleId = $this->argument('schedule');

        $schedule = BackupSchedule::find($scheduleId);

        if (! $schedule) {
            $this->error("Backup schedule not found: {$scheduleId}");

            return self::FAILURE;
        }

        $backups = Backup::with(['databaseServer', 'volume'])
            ->whereRelation('databaseServer', 'backups_enabled', true)
            ->where('backup_schedule_id', $schedule->id)
            ->get();

        if ($backups->isEmpty()) {
            $this->info("No backups configured for schedule: {$schedule->name}.");

            return self::SUCCESS;
        }

        $this->info("Dispatching {$backups->count()} backup(s) for schedule: {$schedule->name}...");

        foreach ($backups as $backup) {
            $server = $backup->databaseServer;

            $snapshots = $backupJobFactory->createSnapshots(
                server: $server,
                method: 'scheduled',
            );

            foreach ($snapshots as $snapshot) {
                ProcessBackupJob::dispatch($snapshot->id);
            }

            $count = count($snapshots);
            $dbInfo = $count === 1 ? '1 database' : "{$count} databases";
            $this->line("  → Dispatched backup for: {$server->name} ({$dbInfo})");
        }

        $this->info('All backup jobs dispatched successfully.');

        return self::SUCCESS;
    }
}
