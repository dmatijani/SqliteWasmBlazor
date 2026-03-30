# V2 Bulk Import/Export

Worker-side bulk operations for fast, memory-safe database import and export.

## Architecture

```
EXPORT: C# request → worker SELECT + pack → V2 MessagePack binary → C# file download
IMPORT: File → C# stream raw bytes (no deserialization) → worker unpack + INSERT → done
SEED:   C# generate + serialize → worker bulk INSERT → done
```

The worker handles all heavy data operations using prepared statement loops (`db.prepare()` + `stmt.bind()/step()/reset()`). C# is a pass-through for large data — it never deserializes individual items.

## V2 MessagePack Format

Each file consists of a V2 header followed by serialized items:

```
[Header: MessagePack array]
  [0]  magic: "SWBV2"
  [1]  schemaHash: string (SHA256 of DTO structure)
  [2]  dataType: string (e.g., "TodoItemDto")
  [3]  appIdentifier: string or null
  [4]  exportedAt: ISO 8601 string
  [5]  recordCount: int
  [6]  mode: 0=Seed, 1=Delta
  [7]  tableName: string (e.g., "TodoItems")
  [8]  columns: [[name, sqlType, csharpType], ...]
  [9]  primaryKeyColumn: string

[Item 0: MessagePack array]  — positional, matching columns order
[Item 1: MessagePack array]
...
[Item N: MessagePack array]
```

## Multi-Part Export

Large databases are automatically split into parts:

1. Part size is estimated from a sample (configurable via `exportPartSizeMb` in `appsettings.json`)
2. Each part is a standard V2 file — independently valid and importable
3. A `.msgpack-meta` JSON file lists all parts
4. Import: pick the meta file, then select all part files

## Conflict Resolution

Delta imports support UPSERT via `ConflictResolutionStrategy`:

| Strategy | SQL | Behavior |
|----------|-----|----------|
| `None` (0) | Plain INSERT | Seed/full import into empty database |
| `LastWriteWins` (1) | ON CONFLICT DO UPDATE WHERE excluded.UpdatedAt > table.UpdatedAt | Newer timestamp wins |
| `LocalWins` (2) | ON CONFLICT DO NOTHING | Only inserts new items |
| `DeltaWins` (3) | ON CONFLICT DO UPDATE | Always overwrites local |

## Type Conversions

The worker converts between MessagePack wire format and SQLite types:

| C# Type | MessagePack Wire | SQLite Type | Notes |
|---------|-----------------|-------------|-------|
| Guid | string (36-char) | TEXT or BLOB | Use `sqlTypeOverrides` for BLOB |
| DateTime | Timestamp ext | TEXT (ISO 8601) | |
| TimeSpan | int64 (Ticks) | TEXT | Worker formats as d.hh:mm:ss |
| DateTimeOffset | [DateTime, short] | TEXT (ISO 8601) | |
| decimal | string | TEXT | Preserves precision |
| bool | true/false | INTEGER (0/1) | |
| byte[] | bin | BLOB | |
| List\<T\> | array | TEXT (JSON) | Via `JsonArray` csharpType |
| Enum | int | INTEGER | |
| long | int64 | INTEGER | Bound as text to avoid int64 precision loss |

### SQL Type Overrides

EF Core's default SQLite type for `Guid` is TEXT. If your entity uses `[Column(TypeName = "BLOB")]`, pass an override:

```csharp
var header = MessagePackFileHeaderV2.Create<TodoItemDto>(
    tableName: "TodoItems",
    primaryKeyColumn: "Id",
    recordCount: count,
    sqlTypeOverrides: new Dictionary<string, string> { ["Id"] = "BLOB" });
```

## Known Limitations

- `sqlite3_column_int64` has boundary errors in Emscripten WASM builds — export reads Int64 columns as SQLITE_TEXT and parses to BigInt
- `long` values > `Number.MAX_SAFE_INTEGER` (2^53-1) are sent as text in EF Core parameters to avoid JSON precision loss
- Raw .db export/import is limited by WASM memory — use V2 bulk for large databases
