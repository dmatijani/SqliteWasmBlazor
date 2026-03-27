using Microsoft.EntityFrameworkCore;
using SqliteWasmBlazor.Models;
using SqliteWasmBlazor.Models.Models;

namespace SqliteWasmBlazor.TestApp.TestInfrastructure.Tests.ImportExport;

/// <summary>
/// Tests backup restore on import failure:
/// 1. Create data in existing DB
/// 2. Attempt import with invalid data (should fail)
/// 3. Restore from backup
/// 4. Verify original data is intact
/// </summary>
internal class RawDatabaseBackupRestoreOnFailureTest(IDbContextFactory<TodoDbContext> factory, ISqliteWasmDatabaseService databaseService)
    : SqliteWasmTest(factory, databaseService)
{
    public override string Name => "ImportRawDatabase_BackupRestoreOnFailure";

    private const string DbName = "TestDb.db";
    private const string BackupName = "TestDb.backup.db";

    public override async ValueTask<string?> RunTestAsync()
    {
        if (DatabaseService is null)
        {
            throw new InvalidOperationException("ISqliteWasmDatabaseService not available");
        }

        // Step 1: Create data
        var originalId = Guid.NewGuid();
        await using (var context = await Factory.CreateDbContextAsync())
        {
            context.TodoItems.AddRange(
                new TodoItem
                {
                    Id = originalId, Title = "Preserved Item", Description = "Must survive failed import",
                    IsCompleted = false, UpdatedAt = DateTime.UtcNow
                },
                new TodoItem
                {
                    Id = Guid.NewGuid(), Title = "Also Preserved", Description = "Must survive",
                    IsCompleted = true, UpdatedAt = DateTime.UtcNow
                }
            );
            await context.SaveChangesAsync();
        }

        // Step 2: Create backup (simulate page workflow)
        if (await DatabaseService.ExistsDatabaseAsync(BackupName))
        {
            await DatabaseService.DeleteDatabaseAsync(BackupName);
        }

        await DatabaseService.CloseDatabaseAsync(DbName);
        await DatabaseService.RenameDatabaseAsync(DbName, BackupName);

        // Step 3: Attempt import with invalid data — should throw ArgumentException
        var invalidData = new byte[1024];
        Random.Shared.NextBytes(invalidData);

        try
        {
            await DatabaseService.ImportDatabaseAsync(DbName, invalidData);
            throw new InvalidOperationException("Expected ArgumentException but import succeeded");
        }
        catch (ArgumentException)
        {
            // Expected — now simulate the restore path
        }

        // Step 4: Restore from backup (simulate page failure recovery)
        if (await DatabaseService.ExistsDatabaseAsync(DbName))
        {
            await DatabaseService.DeleteDatabaseAsync(DbName);
        }

        await DatabaseService.RenameDatabaseAsync(BackupName, DbName);

        // Step 5: Re-open and verify original data is intact
        await using (var context = await Factory.CreateDbContextAsync())
        {
            await context.Database.EnsureCreatedAsync();
        }

        await using (var context = await Factory.CreateDbContextAsync())
        {
            var count = await context.TodoItems.CountAsync();
            if (count != 2)
            {
                throw new InvalidOperationException($"Expected 2 items after restore, got {count}");
            }

            var preserved = await context.TodoItems.FirstOrDefaultAsync(t => t.Id == originalId);
            if (preserved is null || preserved.Title != "Preserved Item")
            {
                throw new InvalidOperationException("Original data not restored correctly");
            }
        }

        // Verify backup is gone after restore
        if (await DatabaseService.ExistsDatabaseAsync(BackupName))
        {
            throw new InvalidOperationException("Backup should not exist after restore");
        }

        return "OK";
    }
}
