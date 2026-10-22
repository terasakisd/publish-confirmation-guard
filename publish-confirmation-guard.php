<?php
/**
 * Plugin Name: Publish Confirmation Guard
 * Plugin URI: https://github.com/terasakisd/publish-confirmation-guard
 * Description: 下書きの記事を「公開」する時だけ、確認ポップアップ（はい/いいえ）を表示します。公開済み記事の「更新」、非公開への変更、非公開から公開への変更では表示されません。クラシックエディタ・ブロックエディタ（Gutenberg）・クイック編集・一括編集に対応。
 * Version: 1.4.0
 * Author: Mikuru Terasaki
 * License: GPL-2.0-or-later
 * Requires at least: 5.8
 * Update URI: https://github.com/terasakisd/publish-confirmation-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Publish_Confirmation_Guard {

	const VERSION     = '1.4.0';
	const GITHUB_REPO = 'terasakisd/publish-confirmation-guard';

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Update URI (github.com) に基づく更新チェック。GitHub の最新リリースと比較する。
		add_filter( 'update_plugins_github.com', array( $this, 'check_for_update' ), 10, 3 );
	}

	/**
	 * GitHub Releases の最新バージョンと比較し、新しければ更新情報を返す
	 *
	 * @param array|false $update      既存の更新情報
	 * @param array       $plugin_data プラグインヘッダー情報
	 * @param string      $plugin_file プラグインのベース名
	 * @return array|false
	 */
	public function check_for_update( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( __FILE__ ) !== $plugin_file ) {
			return $update;
		}

		$release = $this->get_latest_release();
		if ( empty( $release['version'] ) || empty( $release['package'] ) ) {
			return $update;
		}

		if ( version_compare( $release['version'], $plugin_data['Version'], '<=' ) ) {
			return $update;
		}

		return array(
			'slug'    => 'publish-confirmation-guard',
			'version' => $release['version'],
			'url'     => $release['url'],
			'package' => $release['package'],
		);
	}

	/**
	 * GitHub API から最新リリース情報を取得（6時間キャッシュ）
	 *
	 * @return array { version, url, package }
	 */
	private function get_latest_release() {
		$cached = get_transient( 'pcg_latest_release' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$release  = array();
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest',
			array(
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
				'timeout' => 10,
			)
		);

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $body ) && ! empty( $body['tag_name'] ) ) {
				$package = '';
				if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
					foreach ( $body['assets'] as $asset ) {
						if ( ! empty( $asset['browser_download_url'] ) && '.zip' === substr( $asset['name'], -4 ) ) {
							$package = $asset['browser_download_url'];
							break;
						}
					}
				}
				$release = array(
					'version' => ltrim( $body['tag_name'], 'vV' ),
					'url'     => isset( $body['html_url'] ) ? $body['html_url'] : '',
					'package' => $package,
				);
			}
		}

		set_transient( 'pcg_latest_release', $release, 6 * HOUR_IN_SECONDS );

		return $release;
	}

	/**
	 * 対象画面（一覧・編集画面・新規作成画面）でのみスクリプトを読み込む
	 */
	public function enqueue_assets( $hook ) {
		// edit.php = 投稿/固定ページ/CPT一覧, post.php = 編集画面, post-new.php = 新規作成
		if ( ! in_array( $hook, array( 'edit.php', 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		wp_register_script( 'pcg-script', false, array(), self::VERSION, true );
		wp_enqueue_script( 'pcg-script' );
		wp_add_inline_script( 'pcg-script', $this->get_inline_js() );

		wp_enqueue_style( 'dashicons' );

		wp_register_style( 'pcg-style', false, array(), self::VERSION );
		wp_enqueue_style( 'pcg-style' );
		wp_add_inline_style( 'pcg-style', $this->get_inline_css() );
	}

	private function get_inline_js() {
		return <<<JS
(function() {
	'use strict';

	var pendingAction = null;  // 「はい」を押したときに実行する処理
	var confirmed     = false; // 「はい」で確認した後の再クリック連鎖を素通しさせるラッチ

	/* 下書き扱いのステータス（これ→公開 のときだけ確認する） */
	function isDraftStatus(status) {
		return status === 'draft' || status === 'auto-draft';
	}

	/* ---------- モーダル生成 ---------- */
	function buildModal() {
		var overlay = document.createElement('div');
		overlay.id = 'pcg-overlay';
		overlay.innerHTML =
			'<div id="pcg-modal" role="dialog" aria-modal="true" aria-labelledby="pcg-title">' +
				'<h2 id="pcg-title"><span class="dashicons dashicons-megaphone" aria-hidden="true"></span> 記事を公開しますか？</h2>' +
				'<p>公開すると、サイト上で誰でも閲覧できるようになります。</p>' +
				'<div id="pcg-buttons">' +
					'<button type="button" class="button" id="pcg-cancel">いいえ</button>' +
					'<button type="button" class="button button-primary" id="pcg-confirm">はい</button>' +
				'</div>' +
			'</div>';
		document.body.appendChild(overlay);

		overlay.querySelector('#pcg-confirm').addEventListener('click', doConfirm);
		overlay.querySelector('#pcg-cancel').addEventListener('click', closeModal);
		overlay.addEventListener('click', function(e) {
			if (e.target === overlay) closeModal();
		});
		overlay.addEventListener('keydown', function(e) {
			if (e.key === 'Escape') closeModal();
		});

		return overlay;
	}

	function openModal(action) {
		pendingAction = action;

		var overlay = document.getElementById('pcg-overlay') || buildModal();
		overlay.style.display = 'flex';

		// 誤って Enter 連打で公開してしまわないよう、初期フォーカスは「いいえ」
		setTimeout(function() {
			overlay.querySelector('#pcg-cancel').focus();
		}, 50);
	}

	function closeModal() {
		var overlay = document.getElementById('pcg-overlay');
		if (overlay) overlay.style.display = 'none';
		pendingAction = null;
	}

	function doConfirm() {
		var action = pendingAction;
		closeModal();
		if (action) action();
	}

	/* ボタンをブロックしてモーダルを開き、「はい」の後に同じボタンを再クリックする */
	function guard(e, btn) {
		e.preventDefault();
		e.stopPropagation();
		e.stopImmediatePropagation();
		openModal(function() {
			confirmed = true;
			btn.click();
		});
	}

	document.addEventListener('click', function(e) {
		// 「はい」で確認済みの後は、プログラム発行の再クリック連鎖（自分・他プラグイン）には
		// 二度と割り込まない（ラッチ）。確認系プラグイン同士の相互横取りによるループを防ぐ。
		// 未確認のうちは他プラグインの再クリックも確認対象にするため、確認が飛ばされることもない。
		// ユーザーの実クリック（isTrusted）は常に通常どおり判定する。
		if (confirmed && !e.isTrusted) return;

		/* ---------- クラシックエディタの「公開」ボタン ---------- */
		var classicBtn = e.target.closest('#publish');
		if (classicBtn) {
			// 下書き（新規含む）からの操作のみ対象
			var orig = document.getElementById('original_post_status');
			if (!orig || !isDraftStatus(orig.value)) return;

			// 公開状態が「非公開」の場合（＝公開ではなく非公開として保存）は対象外
			var visRadio = document.querySelector('input[name="visibility"]:checked');
			var visHidden = document.getElementById('hidden-post-visibility');
			var visibility = visRadio ? visRadio.value : (visHidden ? visHidden.value : '');
			if (visibility === 'private') return;

			guard(e, classicBtn);
			return;
		}

		/* ---------- ブロックエディタ（Gutenberg）の「公開」ボタン ---------- */
		// 最終確定ボタンのみ対象（公開前パネルを開くトグルは素通し）
		var gbBtn = e.target.closest('.editor-post-publish-button__button');
		if (gbBtn && !gbBtn.classList.contains('editor-post-publish-panel__toggle')) {
			var sel = window.wp && window.wp.data && window.wp.data.select && window.wp.data.select('core/editor');
			if (!sel) return;

			// 保存済みステータスが下書き（新規含む）の場合のみ対象
			var status = sel.getCurrentPostAttribute ? sel.getCurrentPostAttribute('status') : '';
			if (!isDraftStatus(status)) return;

			// 表示状態を「非公開」にしている場合は対象外
			if (sel.getEditedPostVisibility && sel.getEditedPostVisibility() === 'private') return;

			guard(e, gbBtn);
			return;
		}

		/* ---------- クイック編集：下書きの記事を「公開済み」に変更して更新 ---------- */
		var qeBtn = e.target.closest('tr.inline-edit-row .save');
		if (qeBtn && !e.target.closest('#bulk-edit')) {
			var row = qeBtn.closest('tr.inline-edit-row');
			var statusSel = row.querySelector('select[name="_status"]');
			if (!statusSel || statusSel.value !== 'publish') return;

			// 元のステータスが下書きの場合のみ対象
			var m = row.id.match(/^edit-(\\d+)\$/);
			if (!m) return;
			var origEl = document.querySelector('#inline_' + m[1] + ' ._status');
			if (!origEl || !isDraftStatus(origEl.textContent.trim())) return;

			guard(e, qeBtn);
			return;
		}

		/* ---------- 一括編集：下書きの記事を含む選択を「公開済み」に変更して更新 ---------- */
		var bulkBtn = e.target.closest('#bulk_edit');
		if (bulkBtn) {
			var bulkRow = document.getElementById('bulk-edit');
			var bulkStatus = bulkRow && bulkRow.querySelector('select[name="_status"]');
			if (!bulkStatus || bulkStatus.value !== 'publish') return;

			// 選択中の記事に下書きが1件でも含まれる場合のみ対象
			var checked = document.querySelectorAll('input[name="post[]"]:checked');
			var hasDraft = false;
			checked.forEach(function(cb) {
				var st = document.querySelector('#inline_' + cb.value + ' ._status');
				if (st && isDraftStatus(st.textContent.trim())) hasDraft = true;
			});
			if (!hasDraft) return;

			guard(e, bulkBtn);
			return;
		}
	}, true);
})();
JS;
	}

	private function get_inline_css() {
		return <<<CSS
#pcg-overlay {
	display: none;
	position: fixed;
	inset: 0;
	background: rgba(0, 0, 0, 0.6);
	z-index: 100000;
	align-items: center;
	justify-content: center;
}
#pcg-modal {
	background: #fff;
	border-radius: 8px;
	padding: 24px 28px;
	max-width: 420px;
	width: calc(100% - 40px);
	box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
	text-align: center;
}
#pcg-modal h2 {
	margin: 0 0 12px;
	color: #2271b1;
	font-size: 18px;
}
#pcg-modal h2 .dashicons {
	font-size: 22px;
	width: 22px;
	height: 22px;
	vertical-align: text-bottom;
}
#pcg-modal p {
	margin: 0 0 14px;
	font-size: 13px;
	line-height: 1.7;
}
#pcg-buttons {
	display: flex;
	gap: 10px;
	justify-content: center;
	margin-top: 8px;
}
CSS;
	}
}

new Publish_Confirmation_Guard();
