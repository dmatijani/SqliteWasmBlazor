namespace SqliteWasmBlazor;

/// <summary>
/// Typed metadata for worker-side bulk export.
/// All required fields enforced at compile time — prevents JS undefined/fixext issues.
/// </summary>
public record BulkExportMetadata
{
    public required string TableName { get; init; }
    public required string[][] Columns { get; init; }
    public required string PrimaryKeyColumn { get; init; }
    public required string SchemaHash { get; init; }
    public required string DataType { get; init; }
    public string? AppIdentifier { get; init; }
    public int Mode { get; init; }
    public string? Where { get; init; }
    public string[]? WhereParams { get; init; }
    public string? OrderBy { get; init; }
}
