<?php

use App\Jobs\ProcessBackupJob;
use App\Models\BackupSchedule;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use Illuminate\Support\Facades\Queue;

test('fails with non-existent schedule ID', function () {
    $this->artisan('backups:run', ['schedule' => 'non-existent-id'])
        ->expectsOutput('Backup schedule not found: non-existent-id')
        ->assertExitCode(1);
});

test('returns success when no backups configured for schedule', function () {
    $schedule = BackupSchedule::factory()->create(['name' => 'Empty Schedule']);

    $this->artisan('backups:run', ['schedule' => $schedule->id])
        ->expectsOutput("No backups configured for schedule: {$schedule->name}.")
        ->assertExitCode(0);
});

test('dispatches backup jobs for a schedule', function () {
    Queue::fake();

    $server = DatabaseServer::factory()->create(['database_names' => ['production_db']]);
    $schedule = $server->backup->backupSchedule;

    $this->artisan('backups:run', ['schedule' => $schedule->id])
        ->expectsOutputToContain("Dispatching 1 backup(s) for schedule: {$schedule->name}")
        ->expectsOutput('All backup jobs dispatched successfully.')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessBackupJob::class, 1);

    // Verify snapshot was created with scheduled method
    $snapshot = Snapshot::first();
    expect($snapshot->method)->toBe('scheduled')
        ->and($snapshot->database_name)->toBe('production_db');
});

test('dispatches multiple backup jobs for multiple servers on same schedule', function () {
    Queue::fake();

    $schedule = dailySchedule();

    $server1 = DatabaseServer::factory()->create(['name' => 'Server 1', 'database_names' => ['db1']]);
    $server1->backup->update(['backup_schedule_id' => $schedule->id]);

    $server2 = DatabaseServer::factory()->create(['name' => 'Server 2', 'database_names' => ['db2']]);
    $server2->backup->update(['backup_schedule_id' => $schedule->id]);

    $this->artisan('backups:run', ['schedule' => $schedule->id])
        ->expectsOutputToContain('Dispatching 2 backup(s)')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessBackupJob::class, 2);
});

test('only runs backups matching the given schedule', function () {
    Queue::fake();

    $dailySchedule = dailySchedule();
    $weeklySchedule = weeklySchedule();

    $dailyServer = DatabaseServer::factory()->create(['database_names' => ['daily_db']]);
    $dailyServer->backup->update(['backup_schedule_id' => $dailySchedule->id]);

    $weeklyServer = DatabaseServer::factory()->create(['database_names' => ['weekly_db']]);
    $weeklyServer->backup->update(['backup_schedule_id' => $weeklySchedule->id]);

    $this->artisan('backups:run', ['schedule' => $dailySchedule->id])
        ->expectsOutputToContain('Dispatching 1 backup(s)')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessBackupJob::class, 1);
});

test('dispatches multiple jobs for server with multiple databases', function () {
    Queue::fake();

    $server = DatabaseServer::factory()->create([
        'database_names' => ['db1', 'db2', 'db3'],
    ]);
    $schedule = $server->backup->backupSchedule;

    $this->artisan('backups:run', ['schedule' => $schedule->id])
        ->expectsOutputToContain('3 databases')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessBackupJob::class, 3);
});

test('skips disabled backups', function () {
    Queue::fake();

    $schedule = dailySchedule();

    $enabledServer = DatabaseServer::factory()->create(['name' => 'Enabled Server', 'database_names' => ['db1'], 'backups_enabled' => true]);
    $enabledServer->backup->update(['backup_schedule_id' => $schedule->id]);

    $disabledServer = DatabaseServer::factory()->create(['name' => 'Disabled Server', 'database_names' => ['db2'], 'backups_enabled' => false]);
    $disabledServer->backup->update(['backup_schedule_id' => $schedule->id]);

    $this->artisan('backups:run', ['schedule' => $schedule->id])
        ->expectsOutputToContain('Dispatching 1 backup(s)')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessBackupJob::class, 1);
});
