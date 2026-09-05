# Repository working rules

- 最初に対象 Issue と `docs/adr/`、`docs/architecture/`、`docs/database/`、`docs/testing/` の関連ドキュメントを確認し、実装計画を簡潔に示す。
- 軽微な技術判断・実装詳細はユーザー確認を挟まず、既存 ADR / architecture / testing 方針に沿う最小構成を選ぶ。
- Issue の要件が実装上不足する場合、意図を変えない範囲で Issue / PR 説明を補足して進め、判断を PR に記録する。
- main を最新化し、`codex/issue-N-...` ブランチで実装 → ローカル検証 → commit → push → PR 作成 → CI 確認・失敗修正を一連で担当する。ユーザーに Git コマンド実行や PR 作成を依頼しない。
- PR 本文に `Closes #N` を含める。CI の失敗はログで原因を調べ、修正・再pushし、最新commitの全checkが成功するまで対応する。
- ユーザー確認は、要件・仕様の意味が変わる選択、未承認の不可逆・破壊的操作、追加費用、認証情報・秘密情報、ライセンス・法的判断など、ユーザー判断が必要な場合に限定する。
- PR の最終確認とマージはユーザーが行う。エージェントはマージしない。

## Implementation and validation

- スキーマは Laravel Migration を唯一の正とする。dev / test / perf / prod を分離し、通常テストには `keiba_test` のみを使う。
- テストDBガードを弱めてテストを通さない。testing では PostgreSQL、`keiba_test`、config cacheなし、接続URL・read/write上書きなしを要求する。
- `composer validate --strict`、`composer lint`、`composer analyse`、空のtest DBへのMigration、Unit / Integration / Featureテスト、Docker buildを変更に応じて検証する。手順は README と `docs/testing/strategy.md` を参照。
- CI には実データ・秘密情報を使用しない。性能試験やproduction deployは通常CIに追加しない。
- 検証結果・未検証範囲を正確に報告する。CIを確認する前に成功と報告しない。
