<?php
/*
Plugin Name: Tamsaek Profit
Description: 애드센스 수익($)과 광고비(₩)를 매일 입력하면 어제/이번 달/저번 달/올해 누적 순수익을 보여주는 관리자 전용 대시보드
Version: 1.0
Author: Tamsaek
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TAMSAEK_PROFIT_GITHUB_API', 'https://api.github.com/repos/azit4u/Tamsaek-profit/releases/latest' );
define( 'TAMSAEK_PROFIT_VERSION', '1.0' );

class Tamsaek_Profit {

	const TABLE    = 'tamsaek_profit_daily';
	const PER_PAGE = 15;

	private $plugin_base;

	public function __construct() {
		$this->plugin_base = plugin_basename( __FILE__ );

		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_post_tamsaek_profit_save', array( $this, 'save_entry' ) );
		add_action( 'admin_post_tamsaek_profit_delete', array( $this, 'delete_entry' ) );
		add_action( 'wp_ajax_tamsaek_profit_get_rate', array( $this, 'ajax_get_rate' ) );

		// GitHub Release 기반 자동 업데이트 (Tamsaek Tracker와 같은 방식).
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'run_update_migrations' ), 10, 2 );
	}

	private function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public function activate() {
		global $wpdb;

		// 구버전(apd-dashboard) 테이블이 있으면 이름만 바꿔 데이터를 승계한다.
		$old        = $wpdb->prefix . 'apd_daily';
		$new        = $this->table();
		$old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) );
		$new_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) );
		if ( $old_exists === $old && $new_exists !== $new ) {
			$wpdb->query( "RENAME TABLE {$old} TO {$new}" );
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$new} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entry_date DATE NOT NULL,
			adsense_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
			exchange_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
			adspend_krw DECIMAL(14,0) NOT NULL DEFAULT 0,
			pageviews BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			UNIQUE KEY entry_date (entry_date)
		) {$charset};" );
	}

	/* ------------------------------------------------------------------
	 * GitHub Release 자동 업데이트
	 * ---------------------------------------------------------------- */

	/** 최신 Release 정보 (6시간 캐시, 실패 시 1시간 뒤 재시도). */
	private function get_latest_release() {
		$cached = get_transient( 'tamsaek_profit_latest_release' );
		if ( is_object( $cached ) ) {
			return $cached;
		}
		if ( 'error' === $cached ) {
			return null;
		}

		$remote = wp_remote_get(
			TAMSAEK_PROFIT_GITHUB_API,
			array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/vnd.github+json' ) )
		);
		$rel = null;
		if ( ! is_wp_error( $remote ) && 200 === wp_remote_retrieve_response_code( $remote ) ) {
			$rel = json_decode( wp_remote_retrieve_body( $remote ) );
		}
		if ( ! $rel || empty( $rel->tag_name ) ) {
			set_transient( 'tamsaek_profit_latest_release', 'error', HOUR_IN_SECONDS );
			return null;
		}

		$zip_url = '';
		if ( ! empty( $rel->assets ) ) {
			foreach ( $rel->assets as $asset ) {
				if ( ! empty( $asset->browser_download_url ) && '.zip' === substr( $asset->name, -4 ) ) {
					$zip_url = $asset->browser_download_url;
					break;
				}
			}
		}

		$info               = new stdClass();
		$info->version      = ltrim( (string) $rel->tag_name, 'vV' );
		$info->download_url = $zip_url;
		$info->changelog    = ! empty( $rel->body ) ? nl2br( esc_html( $rel->body ) ) : '(변경사항 설명 없음)';

		set_transient( 'tamsaek_profit_latest_release', $info, 6 * HOUR_IN_SECONDS );
		return $info;
	}

	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$info = $this->get_latest_release();
		if ( ! $info || empty( $info->download_url ) ) {
			return $transient;
		}

		$installed_version = ! empty( $transient->checked[ $this->plugin_base ] )
			? $transient->checked[ $this->plugin_base ]
			: TAMSAEK_PROFIT_VERSION;

		$res              = new stdClass();
		$res->slug        = 'Tamsaek-profit';
		$res->plugin      = $this->plugin_base;
		$res->new_version = $info->version;

		if ( version_compare( $installed_version, $info->version, '<' ) ) {
			$res->package                        = $info->download_url;
			$transient->response[ $res->plugin ] = $res;
		} else {
			$res->new_version                     = $installed_version;
			$res->package                         = '';
			$transient->no_update[ $res->plugin ] = $res;
		}
		return $transient;
	}

	public function plugin_info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || 'Tamsaek-profit' !== $args->slug ) {
			return $res;
		}

		$info = $this->get_latest_release();
		if ( ! $info ) {
			return $res;
		}

		$res                = new stdClass();
		$res->name          = 'Tamsaek Profit';
		$res->slug          = 'Tamsaek-profit';
		$res->version       = $info->version;
		$res->author        = 'Tamsaek';
		$res->download_link = $info->download_url;
		$res->tested        = '6.9';
		$res->requires      = '6.0';

		$description = '
			<p>수익 대시보드 플러그인 <strong>탐색</strong> — 애드센스 수익($)과 광고비(₩)를 매일 입력하면 순수익을 자동 계산해 보여줍니다.</p>
			<h4>주요 기능</h4>
			<ul>
				<li>어제 / 이번 달 / 저번 달 / 올해 누적 순수익 카드</li>
				<li>환율 자동 조회 (ECB 공식 → KITA 매매기준율 순)</li>
				<li>페이지 RPM 입력 → 월간 가중평균 RPM 자동 계산</li>
				<li>입력 내역 페이징 목록, 수정·삭제</li>
			</ul>
			<h4>참고</h4>
			<ul>
				<li>모든 데이터는 사이트 자체 DB에만 저장되며 외부로 전송하지 않음</li>
				<li>관리자(manage_options)만 접근 가능</li>
			</ul>';

		$res->sections = array(
			'description' => $description,
			'changelog'   => $info->changelog,
		);
		return $res;
	}

	/** 자동 업데이트 직후 테이블 스키마 보정. */
	public function run_update_migrations( $upgrader_object, $options ) {
		if ( isset( $options['action'], $options['type'] ) && 'update' === $options['action'] && 'plugin' === $options['type'] ) {
			if ( isset( $options['plugins'] ) && is_array( $options['plugins'] ) && in_array( $this->plugin_base, $options['plugins'], true ) ) {
				$this->activate();
			}
		}
	}

	/* ------------------------------------------------------------------
	 * 데이터 입력/삭제
	 * ---------------------------------------------------------------- */

	public function admin_menu() {
		add_menu_page(
			'수익 대시보드',
			'수익 대시보드',
			'manage_options',
			'tamsaek-profit',
			array( $this, 'render_page' ),
			'dashicons-chart-area',
			3
		);
	}

	public function save_entry() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '권한이 없습니다.' );
		}
		check_admin_referer( 'tamsaek_profit_save' );

		$date = isset( $_POST['entry_date'] ) ? sanitize_text_field( wp_unslash( $_POST['entry_date'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			wp_die( '날짜 형식이 올바르지 않습니다.' );
		}

		// 어제까지만 입력 가능 (오늘·미래 차단).
		$yesterday = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-1 day' )->format( 'Y-m-d' );
		if ( $date > $yesterday ) {
			wp_die( '오늘 이후 날짜는 입력할 수 없습니다. 어제까지의 확정치만 입력하세요.' );
		}

		$usd = round( floatval( $_POST['adsense_usd'] ?? 0 ), 2 );
		$rpm = floatval( $_POST['page_rpm'] ?? 0 );
		// RPM을 직접 입력받고 페이지뷰는 역산해 저장한다 (월간 RPM 가중평균 계산용).
		$pageviews = $rpm > 0 ? (int) round( $usd / $rpm * 1000 ) : 0;

		global $wpdb;

		// 수정 모드에서 날짜를 바꾼 경우: 원래 행을 지워서 중복 생성을 막는다.
		$edit_id = absint( $_POST['edit_id'] ?? 0 );
		if ( $edit_id ) {
			$wpdb->delete( $this->table(), array( 'id' => $edit_id ), array( '%d' ) );
		}

		$wpdb->replace(
			$this->table(),
			array(
				'entry_date'    => $date,
				'adsense_usd'   => $usd,
				'exchange_rate' => round( floatval( $_POST['exchange_rate'] ?? 0 ), 2 ),
				'adspend_krw'   => round( floatval( $_POST['adspend_krw'] ?? 0 ) ),
				'pageviews'     => $pageviews,
			),
			array( '%s', '%f', '%f', '%f', '%d' )
		);

		wp_safe_redirect( admin_url( 'admin.php?page=tamsaek-profit&saved=1' ) );
		exit;
	}

	public function delete_entry() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '권한이 없습니다.' );
		}
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'tamsaek_profit_delete_' . $id );

		global $wpdb;
		$wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=tamsaek-profit&deleted=1' ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * 환율 조회
	 * ---------------------------------------------------------------- */

	/** KITA에서 해당 날짜의 최종 회차 매매기준율(KB 고시)을 가져온다. 키 불필요, 비영업일이면 직전 영업일로. */
	private function kita_rate( $date ) {
		$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
		$d  = DateTimeImmutable::createFromFormat( 'Y-m-d', $date );
		if ( ! $d ) {
			return null;
		}
		for ( $i = 0; $i < 7; $i++ ) {
			$day = $d->modify( "-{$i} day" );

			// 1) 해당 날짜의 고시 회차 목록 → 최종 회차.
			$res = wp_remote_post(
				'https://www.kita.net/cmmrcInfo/ehgtGnrlzInfo/calendarAjax.do',
				array(
					'timeout'    => 8,
					'user-agent' => $ua,
					'headers'    => array( 'Content-Type' => 'application/json' ),
					'body'       => wp_json_encode( array( 'sDate' => $day->format( 'Y.m.d' ) ) ),
				)
			);
			if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
				return null;
			}
			$list = json_decode( wp_remote_retrieve_body( $res ), true );
			$max  = 0;
			foreach ( (array) ( $list['noteTimesList'] ?? array() ) as $t ) {
				$max = max( $max, intval( $t['noteTimes'] ?? 0 ) );
			}
			if ( $max < 1 ) {
				continue; // 주말·공휴일 → 하루 전으로.
			}

			// 2) 그 날짜의 최종 회차 페이지에서 USD 매매기준율 추출.
			$res = wp_remote_post(
				'https://www.kita.net/cmmrcInfo/ehgtGnrlzInfo/rltmEhgt.do',
				array(
					'timeout'    => 8,
					'user-agent' => $ua,
					'body'       => array(
						'sDate'     => $day->format( 'Y.m.d' ),
						'noteTimes' => (string) $max,
					),
				)
			);
			if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
				return null;
			}
			if ( preg_match( '/monyCd=USD.*?text-right">\s*([\d,]+(?:\.\d+)?)/s', wp_remote_retrieve_body( $res ), $m ) ) {
				return array(
					'rate' => round( floatval( str_replace( ',', '', $m[1] ) ), 2 ),
					'date' => $day->format( 'Y-m-d' ) . ' 최종 매매기준율',
				);
			}
			return null;
		}
		return null;
	}

	/** 서버에서 환율 조회. 1차 frankfurter(ECB 공식, 키 불필요), 2차 KITA 매매기준율(KB). */
	public function ajax_get_rate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		check_ajax_referer( 'tamsaek_profit_get_rate' );

		$date = sanitize_text_field( wp_unslash( $_GET['date'] ?? '' ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			wp_send_json_error();
		}

		$res = wp_remote_get( "https://api.frankfurter.dev/v1/{$date}?from=USD&to=KRW", array( 'timeout' => 8 ) );
		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
			$body = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( ! empty( $body['rates']['KRW'] ) ) {
				wp_send_json_success(
					array(
						'rate' => round( floatval( $body['rates']['KRW'] ), 2 ),
						'date' => $body['date'] ?? $date,
					)
				);
			}
		}

		$got = $this->kita_rate( $date );
		if ( $got ) {
			wp_send_json_success( $got );
		}

		wp_send_json_error();
	}

	/* ------------------------------------------------------------------
	 * 집계
	 * ---------------------------------------------------------------- */

	/** 기간 합계: [adsense_usd 합, adsense_krw 합(일별 환율 적용), 광고비 합, 페이지뷰 합] */
	private function sums( $from, $to ) {
		global $wpdb;
		$table = $this->table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(adsense_usd), 0) AS usd,
					COALESCE(SUM(adsense_usd * exchange_rate), 0) AS krw,
					COALESCE(SUM(adspend_krw), 0) AS adspend,
					COALESCE(SUM(pageviews), 0) AS pv
				FROM {$table}
				WHERE entry_date BETWEEN %s AND %s",
				$from,
				$to
			),
			ARRAY_A
		);
		return array_map( 'floatval', $row );
	}

	private function card_data( $from, $to ) {
		$s     = $this->sums( $from, $to );
		$net   = $s['krw'] - $s['adspend'];
		$ratio = $s['krw'] > 0 ? ( $s['adspend'] / $s['krw'] * 100 ) : 0;
		$rpm   = $s['pv'] > 0 ? ( $s['usd'] / $s['pv'] * 1000 ) : 0;
		return array(
			'net'     => $net,
			'usd'     => $s['usd'],
			'adsense' => $s['krw'],
			'adspend' => $s['adspend'],
			'ratio'   => $ratio,
			'rpm'     => $rpm,
		);
	}

	/* ------------------------------------------------------------------
	 * 화면
	 * ---------------------------------------------------------------- */

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$table = $this->table();
		$tz    = wp_timezone();

		$today       = new DateTimeImmutable( 'now', $tz );
		$yesterday   = $today->modify( '-1 day' )->format( 'Y-m-d' );
		$month_first = $today->format( 'Y-m-01' );
		$month_last  = $today->format( 'Y-m-t' );
		$prev        = $today->modify( 'first day of last month' );
		$prev_first  = $prev->format( 'Y-m-01' );
		$prev_last   = $prev->format( 'Y-m-t' );
		$year_first  = $today->format( 'Y-01-01' );
		$year_last   = $today->format( 'Y-12-31' );

		$cards = array(
			array( '어제 순수익', $this->card_data( $yesterday, $yesterday ), false ),
			array( '이번 달 순수익', $this->card_data( $month_first, $month_last ), true ),
			array( '저번 달 순수익', $this->card_data( $prev_first, $prev_last ), false ),
			array( '올해 누적 순수익', $this->card_data( $year_first, $year_last ), false ),
		);

		// 목록 + 페이징.
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged  = min( $paged, $pages );
		$offset = ( $paged - 1 ) * self::PER_PAGE;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY entry_date DESC LIMIT %d OFFSET %d",
				self::PER_PAGE,
				$offset
			),
			ARRAY_A
		);

		// 입력 폼 기본값: 어제 날짜, 마지막으로 입력한 환율.
		$last_rate = $wpdb->get_var( "SELECT exchange_rate FROM {$table} ORDER BY entry_date DESC LIMIT 1" );
		$last_rate = $last_rate ? floatval( $last_rate ) : '';
		?>
		<div class="wrap apd-wrap">
			<h1>수익 대시보드</h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>저장했습니다.</p></div>
			<?php elseif ( isset( $_GET['deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>삭제했습니다.</p></div>
			<?php endif; ?>

			<div class="apd-layout">
				<div class="apd-cards">
					<?php foreach ( $cards as $c ) : list( $title, $d, $highlight ) = $c; ?>
						<div class="apd-card<?php echo $highlight ? ' apd-card--highlight' : ''; ?>">
							<div class="apd-card__title"><?php echo esc_html( $title ); ?></div>
							<div class="apd-card__net<?php echo $highlight ? ' apd-green' : ''; ?>">
								₩<?php echo esc_html( number_format( $d['net'] ) ); ?>
							</div>
							<div class="apd-card__usd">$<?php echo esc_html( number_format( $d['usd'], 2 ) ); ?></div>
							<div class="apd-card__rows">
								<div class="apd-row">
									<span class="apd-row__label">애드센스</span>
									<strong>₩<?php echo esc_html( number_format( $d['adsense'] ) ); ?></strong>
								</div>
								<div class="apd-row">
									<span class="apd-row__label">광고비</span>
									<span class="apd-row__right">
										<strong>₩<?php echo esc_html( number_format( $d['adspend'] ) ); ?></strong>
										<em class="<?php echo $d['ratio'] >= 60 ? 'apd-red' : 'apd-gold'; ?>">
											<?php echo esc_html( number_format( $d['ratio'], 1 ) ); ?>%
										</em>
									</span>
								</div>
							</div>
							<div class="apd-card__rpm">RPM $<?php echo esc_html( number_format( $d['rpm'], 2 ) ); ?></div>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="apd-panel">
					<h2 class="apd-panel__title">일별 입력</h2>
					<p id="apd-edit-note" class="apd-edit-note" hidden></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'tamsaek_profit_save' ); ?>
						<input type="hidden" name="action" value="tamsaek_profit_save">
						<input type="hidden" name="edit_id" id="apd-edit-id" value="">

						<div class="apd-field">
							<label for="apd-date">날짜</label>
							<input type="date" id="apd-date" name="entry_date" value="<?php echo esc_attr( $yesterday ); ?>" max="<?php echo esc_attr( $yesterday ); ?>" required>
							<span class="apd-hint">어제까지만 입력할 수 있습니다 (확정치 기준).</span>
						</div>
						<div class="apd-field">
							<label for="apd-usd">애드센스 수익 ($)</label>
							<input type="number" step="0.01" min="0" id="apd-usd" name="adsense_usd" placeholder="399.81" required>
						</div>
						<div class="apd-field">
							<label for="apd-rate">환율 (₩/$)</label>
							<input type="number" step="0.01" min="0" id="apd-rate" name="exchange_rate" value="<?php echo esc_attr( $last_rate ); ?>" placeholder="1420" required>
							<span id="apd-rate-status" class="apd-hint"></span>
						</div>
						<div class="apd-field">
							<label for="apd-adspend">광고비 (₩)</label>
							<input type="number" step="1" min="0" id="apd-adspend" name="adspend_krw" placeholder="333490" required>
						</div>
						<div class="apd-field">
							<label for="apd-rpm">페이지 RPM ($)</label>
							<input type="number" step="0.01" min="0" id="apd-rpm" name="page_rpm" placeholder="62.15">
							<span class="apd-hint">애드센스 실적의 "페이지 RPM" 그대로 입력</span>
						</div>

						<p class="apd-hint">같은 날짜로 저장하면 기존 값을 덮어씁니다.</p>
						<button type="submit" class="button button-primary button-large apd-submit">저장</button>
					</form>
				</div>
			</div>

			<h2 class="apd-list-title">입력 내역 <span class="apd-count">(총 <?php echo esc_html( number_format( $total ) ); ?>일)</span></h2>
			<table class="widefat striped apd-table">
				<thead>
					<tr>
						<th>날짜</th>
						<th>애드센스 ($)</th>
						<th>환율</th>
						<th>애드센스 (₩)</th>
						<th>광고비 (₩)</th>
						<th>순수익 (₩)</th>
						<th>RPM ($)</th>
						<th class="apd-col-actions"></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="8">아직 입력된 데이터가 없습니다. 오른쪽 입력 폼에 어제 값을 넣어보세요.</td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $r ) :
						$usd  = floatval( $r['adsense_usd'] );
						$rate = floatval( $r['exchange_rate'] );
						$krw  = $usd * $rate;
						$net  = $krw - floatval( $r['adspend_krw'] );
						$rpm  = $r['pageviews'] > 0 ? $usd / $r['pageviews'] * 1000 : 0;
						$del  = wp_nonce_url(
							admin_url( 'admin-post.php?action=tamsaek_profit_delete&id=' . absint( $r['id'] ) ),
							'tamsaek_profit_delete_' . absint( $r['id'] )
						);
						?>
						<tr>
							<td><?php echo esc_html( $r['entry_date'] ); ?></td>
							<td>$<?php echo esc_html( number_format( $usd, 2 ) ); ?></td>
							<td><?php echo esc_html( number_format( $rate, 2 ) ); ?></td>
							<td>₩<?php echo esc_html( number_format( $krw ) ); ?></td>
							<td>₩<?php echo esc_html( number_format( floatval( $r['adspend_krw'] ) ) ); ?></td>
							<td><strong class="<?php echo $net >= 0 ? 'apd-net-plus' : 'apd-net-minus'; ?>">₩<?php echo esc_html( number_format( $net ) ); ?></strong></td>
							<td>$<?php echo esc_html( number_format( $rpm, 2 ) ); ?></td>
							<td class="apd-col-actions">
								<a href="#" class="apd-edit"
									data-id="<?php echo esc_attr( absint( $r['id'] ) ); ?>"
									data-date="<?php echo esc_attr( $r['entry_date'] ); ?>"
									data-usd="<?php echo esc_attr( $usd ); ?>"
									data-rate="<?php echo esc_attr( $rate ); ?>"
									data-adspend="<?php echo esc_attr( intval( $r['adspend_krw'] ) ); ?>"
									data-rpm="<?php echo esc_attr( $rpm > 0 ? number_format( $rpm, 2, '.', '' ) : '' ); ?>">수정</a>
								<span class="apd-sep">|</span>
								<a href="<?php echo esc_url( $del ); ?>" class="apd-delete"
									onclick="return confirm('<?php echo esc_js( $r['entry_date'] ); ?> 데이터를 삭제할까요?');">삭제</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages apd-paging">
					<?php
					echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $pages,
							'prev_text' => '‹ 이전',
							'next_text' => '다음 ›',
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>

		<style>
			.apd-wrap { max-width: 1200px; }
			.apd-wrap h1 { font-weight: 700; }

			.apd-layout {
				display: grid;
				grid-template-columns: minmax(0, 1fr) 360px;
				gap: 24px;
				align-items: start;
				margin-top: 8px;
			}
			@media (max-width: 1280px) { .apd-layout { grid-template-columns: 1fr; } }

			.apd-cards {
				display: grid;
				grid-template-columns: repeat(2, minmax(0, 1fr));
				gap: 20px;
			}
			@media (max-width: 782px) { .apd-cards { grid-template-columns: 1fr; } }

			.apd-card {
				background: #fff;
				border-radius: 20px;
				padding: 26px 30px;
				box-shadow: 0 6px 18px rgba(23, 60, 45, .06);
				border: 3px solid transparent;
			}
			.apd-card--highlight { border-color: #22c55e; }
			.apd-card__title { color: #42566b; font-size: 16px; font-weight: 600; margin-bottom: 12px; }
			.apd-card__net { font-size: 34px; font-weight: 800; color: #101828; letter-spacing: -1px; line-height: 1.15; }
			.apd-card__net.apd-green { color: #12b76a; }
			.apd-card__usd { color: #98a2b3; font-size: 16px; font-weight: 600; margin: 6px 0 18px; }
			.apd-card__rows { border-top: 1px solid #eaecf0; padding-top: 12px; }
			.apd-row { display: flex; justify-content: space-between; align-items: flex-start; padding: 6px 0; color: #475467; font-size: 15px; }
			.apd-row strong { color: #101828; font-size: 16px; font-weight: 800; }
			.apd-row__right { text-align: right; }
			.apd-row__right em { display: block; font-style: normal; font-weight: 800; font-size: 15px; margin-top: 3px; }
			.apd-gold { color: #eaa800; }
			.apd-red { color: #e5484d; }
			.apd-card__rpm { border-top: 1px solid #eaecf0; margin-top: 14px; padding-top: 14px; color: #1d6ae5; font-weight: 800; font-size: 17px; }

			.apd-panel {
				background: #fff;
				border-radius: 20px;
				padding: 22px 26px 26px;
				box-shadow: 0 6px 18px rgba(23, 60, 45, .06);
			}
			.apd-panel__title { margin: 0 0 6px; padding: 0; font-size: 18px; font-weight: 700; color: #101828; }
			.apd-edit-note {
				background: #fef4e6;
				border: 1px solid #f5c97b;
				border-radius: 8px;
				padding: 8px 12px;
				color: #8a5a00;
				font-size: 13px;
			}
			.apd-field { margin: 14px 0 0; }
			.apd-field label { display: block; font-weight: 600; color: #344054; margin-bottom: 6px; }
			.apd-field input { width: 100%; border-radius: 8px; }
			.apd-hint { display: block; color: #98a2b3; font-size: 12px; margin-top: 6px; }
			.apd-submit { width: 100%; margin-top: 10px; }

			.apd-list-title { margin-top: 34px; }
			.apd-count { color: #98a2b3; font-size: 14px; font-weight: 400; }
			.apd-table { border-radius: 12px; overflow: hidden; }
			.apd-table th { font-weight: 700; }
			.apd-net-plus { color: #101828; }
			.apd-net-minus { color: #e5484d; }
			.apd-col-actions { text-align: right; white-space: nowrap; }
			.apd-sep { color: #d0d5dd; margin: 0 2px; }
			.apd-edit { color: #1d6ae5; text-decoration: none; }
			.apd-delete { color: #b91c1c; text-decoration: none; }
			.apd-paging { float: none; text-align: center; margin: 14px 0; }
		</style>

		<script>
		(function () {
			var dateInput = document.getElementById('apd-date');
			var usdInput  = document.getElementById('apd-usd');
			var rateInput = document.getElementById('apd-rate');
			var spendIn   = document.getElementById('apd-adspend');
			var rpmInput  = document.getElementById('apd-rpm');
			var status    = document.getElementById('apd-rate-status');
			var editNote  = document.getElementById('apd-edit-note');
			if (!dateInput || !rateInput) return;

			var maxDate  = dateInput.getAttribute('max');
			var editIdIn = document.getElementById('apd-edit-id');
			var rateUrl  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) . '?action=tamsaek_profit_get_rate&_wpnonce=' . wp_create_nonce( 'tamsaek_profit_get_rate' ) ); ?>;

			// 빠르게 날짜를 바꿀 때 늦게 도착한 응답이 최신 값을 덮어쓰지 않도록 순번을 매긴다.
			var reqSeq = 0;
			var lastFetched = '';

			function fetchRate(date) {
				var seq = ++reqSeq;
				lastFetched = date;
				status.textContent = '환율 조회 중…';
				fetch(rateUrl + '&date=' + encodeURIComponent(date), { credentials: 'same-origin' })
					.then(function (res) {
						if (!res.ok) throw new Error(res.status);
						return res.json();
					})
					.then(function (data) {
						if (seq !== reqSeq) return;
						if (data && data.success && data.data && data.data.rate) {
							rateInput.value = data.data.rate;
							status.textContent = data.data.date + ' 기준 자동 조회됨';
						} else {
							status.textContent = '환율을 찾지 못했습니다. 직접 입력해주세요.';
						}
					})
					.catch(function () {
						if (seq !== reqSeq) return;
						status.textContent = '환율 조회에 실패했습니다. 다시 시도하려면 날짜를 다시 선택하세요.';
					});
			}

			function onDateChanged() {
				// 달력 max 속성을 우회해 미래 날짜를 입력한 경우 어제로 되돌린다.
				if (maxDate && dateInput.value > maxDate) {
					dateInput.value = maxDate;
				}
				// 같은 날짜로 이벤트가 중복 발생해도 한 번만 조회한다.
				if (dateInput.value && dateInput.value !== lastFetched) fetchRate(dateInput.value);
			}
			dateInput.addEventListener('change', onDateChanged);
			dateInput.addEventListener('input', onDateChanged);

			if (dateInput.value) fetchRate(dateInput.value);

			function clearEditMode() {
				editIdIn.value = '';
				editNote.hidden = true;
				editNote.textContent = '';
			}

			// 목록의 "수정" 클릭 → 해당 행 값을 폼에 채우고 수정 모드로 전환.
			// 수정 모드에서 날짜를 바꾸면 그 행이 새 날짜로 "이동"한다 (중복 생성 안 됨).
			document.querySelectorAll('.apd-edit').forEach(function (btn) {
				btn.addEventListener('click', function (e) {
					e.preventDefault();
					dateInput.value = btn.dataset.date;
					lastFetched     = btn.dataset.date; // 저장된 환율을 유지하도록 자동 조회를 건너뛴다.
					usdInput.value  = btn.dataset.usd;
					rateInput.value = btn.dataset.rate;
					spendIn.value   = btn.dataset.adspend;
					rpmInput.value  = btn.dataset.rpm || '';
					editIdIn.value  = btn.dataset.id;
					status.textContent = '';
					editNote.hidden = false;
					editNote.innerHTML = '';
					editNote.appendChild(document.createTextNode(btn.dataset.date + ' 데이터를 수정 중입니다. '));
					var cancel = document.createElement('a');
					cancel.href = '#';
					cancel.textContent = '수정 취소';
					cancel.addEventListener('click', function (ev) {
						ev.preventDefault();
						clearEditMode();
					});
					editNote.appendChild(cancel);
					document.querySelector('.apd-panel').scrollIntoView({ behavior: 'smooth', block: 'start' });
				});
			});
		})();
		</script>
		<?php
	}
}

new Tamsaek_Profit();
