# keiba

競馬道OnLine KD3 と JRA-VAN JV-Link を組み合わせ、競走データの蓄積・分析・可視化を行うための個人開発プロジェクトです。

## 方針

- Backend: PHP / Laravel
- Database: PostgreSQL
- Local runtime: WSL2 Ubuntu 26.04 + Docker Compose
- JV-Link collector: C# / Windows
- Schema management: Laravel Migration
- CI/CD: GitHub Actions
- Environments: dev / test / perf / prod を分離
- KD3: 確定・履歴・競馬ブック独自情報の主データ
- JV-Link: 開催スケジュール、過去時系列、当日速報・時系列データの取得

設計ドキュメントは `docs/` 以下で管理します。
