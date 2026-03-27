using Microsoft.EntityFrameworkCore;
using SqliteWasmBlazor.Models;

namespace SqliteWasmBlazor.TestApp.TestInfrastructure.Tests.ImportExport;

internal class RawDatabaseImportInvalidFileTest(IDbContextFactory<TodoDbContext> factory, ISqliteWasmDatabaseService databaseService)
    : SqliteWasmTest(factory, databaseService)
{
    public override string Name => "ImportRawDatabase_InvalidFile";

    public override async ValueTask<string?> RunTestAsync()
    {
        if (DatabaseService is null)
        {
            throw new InvalidOperationException("ISqliteWasmDatabaseService not available");
        }

        // Try importing random non-SQLite bytes
        var randomData = new byte[1024];
        Random.Shared.NextBytes(randomData);

        try
        {
            await DatabaseService.ImportDatabaseAsync("TestDb.db", randomData);
            throw new InvalidOperationException("Expected ArgumentException but import succeeded");
        }
        catch (ArgumentException)
        {
            // Expected — invalid SQLite header detected
        }

        return "OK";
    }
}
