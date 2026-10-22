# Publish Confirmation Guard

WordPress の記事をうっかり公開してしまうのを防ぐプラグインです。**下書きの記事を「公開」するときだけ**、確認ポップアップ（はい / いいえ）を表示します。

![WordPress 5.8+](https://img.shields.io/badge/WordPress-5.8%2B-blue)
![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-green)

## 機能

- 下書き（新規作成含む）→ 公開 のときだけ確認ダイアログを表示
- 以下の操作では表示されません（邪魔をしません）
  - 公開済み記事の「更新」
  - 非公開として保存する操作
  - 下書きとして保存する操作
- 対応画面
  - クラシックエディタ
  - ブロックエディタ（Gutenberg）
  - クイック編集
  - 一括編集（選択に下書きが含まれる場合のみ）
- 誤操作防止のため、ダイアログの初期フォーカスは「いいえ」
- プログラム発行のクリック（他プラグインの再クリック等）には割り込まないため、確認系の他プラグインと競合してもループしません

## インストール

1. [Releases](https://github.com/terasakisd/publish-confirmation-guard/releases/latest) から `publish-confirmation-guard.zip` をダウンロード
2. WordPress 管理画面 → プラグイン → 新規追加 → プラグインのアップロード から zip をアップロードして有効化

## 更新について

このプラグインは GitHub Releases と連動した自動更新に対応しています。新しいリリースが公開されると、WordPress 管理画面のプラグイン一覧に通常のプラグインと同じように更新通知が表示され、ワンクリックで更新できます（WordPress 5.8 以上）。

## 開発者向け: リリース手順

1. `publish-confirmation-guard.php` のヘッダー `Version` と `const VERSION` を上げる
2. コミットしてタグを打つ（例: `v1.4.0`）
3. プラグインフォルダ構造の zip を作成して GitHub Release の添付ファイルとして公開する

```sh
mkdir -p build/publish-confirmation-guard
cp publish-confirmation-guard.php build/publish-confirmation-guard/
(cd build && zip -r ../publish-confirmation-guard.zip publish-confirmation-guard)
gh release create v1.4.0 publish-confirmation-guard.zip --title "v1.4.0" --notes "変更内容"
rm -rf build publish-confirmation-guard.zip
```

> zip の中身は必ず `publish-confirmation-guard/` フォルダで包んでください。更新時に同じディレクトリへ正しく上書きされるために必要です。

## ライセンス

[GPL-2.0-or-later](LICENSE)
