# KD3 Domain ER

```mermaid
erDiagram
    VENUES ||--o{ RACE_CALENDARS : has
    RACE_CALENDARS ||--o{ RACES : has
    RACES ||--o| RACE_ENTRIES : current
    RACE_ENTRIES ||--o{ RACE_ENTRY_RUNNERS : has
    HORSES ||--o{ RACE_ENTRY_RUNNERS : runs
    JOCKEYS ||--o{ RACE_ENTRY_RUNNERS : rides
    TRAINERS ||--o{ RACE_ENTRY_RUNNERS : trains
    RACE_ENTRY_RUNNERS ||--o{ RUNNER_WORKOUTS : has
    RACE_ENTRY_RUNNERS ||--o{ RUNNER_SPEED_INDICES : has
    RUNNER_SPEED_INDICES ||--o| RUNNER_SPEED_INDEX_REFERENCES : resolved_as
    RACE_RESULT_RUNNERS ||--o{ RUNNER_SPEED_INDEX_REFERENCES : referenced_by
    RUNNER_SPEED_INDICES ||--o{ RACE_SPEED_METRICS : derives
    RACES ||--o{ RACE_SPEED_STATISTICS : aggregates
    RACES ||--o| RACE_RESULTS : current
    RACE_RESULTS ||--o{ RACE_RESULT_RUNNERS : has
    HORSES ||--o{ RACE_RESULT_RUNNERS : finished
    HORSES ||--o{ HORSE_ENTRY_SNAPSHOTS : snapshots
    HORSES ||--o{ HORSE_RESULT_SNAPSHOTS : snapshots
    RACES ||--o{ RACE_ODDS : markets
    RACES o|--o{ RACE_COMMENTS : referenced_by
    SOURCE_FILES ||--o{ KD3_IMPORT_RUNS : audited_by
    SOURCE_FILES ||--o{ RACE_ENTRIES : lineage
    SOURCE_FILES ||--o{ RACE_RESULTS : lineage
    SOURCE_FILES ||--o{ RACE_ODDS : lineage
```

`runner_speed_indices` は `kol_den2.kd3` が配布した値そのものを保持する source fact であり、参照レースを内包しない。
参照先は再計算可能な派生データとして `runner_speed_index_references` に分離する。未解決の場合は reference row を作成しない。

`kol_uma.kd3` の直近5走・6〜55走は canonical history として保存しない。過去走は `races` / `race_results` / `race_result_runners` から導出する。

`source_identifiers.entity_id` は複数entity tableを指すgeneric mappingのため、既存設計どおり物理FKを持たない。その他の参照はMigrationでRESTRICT FKを持つ。
