using SqliteWasmBlazor;

namespace SqliteWasmBlazor.Models.Extensions;

/// <summary>
/// Model-specific schema validation wrappers.
/// Delegates to the generic DbContext.ValidateImportedSchemaAsync in SqliteWasmBlazor.
/// </summary>
public static class ModelSchemaValidationExtensions
{
    /// <summary>
    /// Validates that the imported database has the required TodoDb schema.
    /// </summary>
    public static Task ValidateSchemaAsync(this TodoDbContext context)
    {
        return context.ValidateImportedSchemaAsync("TodoDb.db");
    }
}
