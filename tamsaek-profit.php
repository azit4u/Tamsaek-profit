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

	const TABLE = 'tamsaek_profit_daily';

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
				<li>입력 내역 년·월별 조회, 수정·삭제, 모바일 카드형</li>
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
			'광고수익 대시보드',
			'탐색 광고수익',
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
		$parsed_date = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		if ( ! $parsed_date || $parsed_date->format( 'Y-m-d' ) !== $date ) {
			wp_die( '날짜 형식이 올바르지 않습니다.' );
		}

		// 어제까지만 입력 가능 (오늘·미래 차단).
		$yesterday = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-1 day' )->format( 'Y-m-d' );
		if ( $date > $yesterday ) {
			wp_die( '오늘 이후 날짜는 입력할 수 없습니다. 어제까지의 확정치만 입력하세요.' );
		}

		$usd_raw     = sanitize_text_field( wp_unslash( $_POST['adsense_usd'] ?? '' ) );
		$rate_raw    = sanitize_text_field( wp_unslash( $_POST['exchange_rate'] ?? '' ) );
		$adspend_raw = sanitize_text_field( wp_unslash( $_POST['adspend_krw'] ?? '' ) );
		$rpm_raw     = sanitize_text_field( wp_unslash( $_POST['page_rpm'] ?? '' ) );

		if ( ! is_numeric( $usd_raw ) || ! is_numeric( $rate_raw ) || ! is_numeric( $adspend_raw ) || ( '' !== $rpm_raw && ! is_numeric( $rpm_raw ) ) ) {
			wp_die( '금액과 RPM은 숫자로 입력하세요.' );
		}

		$usd     = round( floatval( $usd_raw ), 2 );
		$rate    = round( floatval( $rate_raw ), 2 );
		$adspend = round( floatval( $adspend_raw ) );
		$rpm     = '' === $rpm_raw ? 0 : floatval( $rpm_raw );

		if ( ! is_finite( $usd ) || ! is_finite( $rate ) || ! is_finite( $adspend ) || ! is_finite( $rpm ) || $usd < 0 || $rate <= 0 || $adspend < 0 || $rpm < 0 ) {
			wp_die( '수익·광고비·RPM은 0 이상, 환율은 0보다 큰 값으로 입력하세요.' );
		}

		// RPM을 직접 입력받고 페이지뷰는 역산해 저장한다 (월간 RPM 가중평균 계산용).
		$pageviews = $rpm > 0 ? (int) round( $usd / $rpm * 1000 ) : 0;

		global $wpdb;
		$table   = $this->table();
		$edit_id = absint( $_POST['edit_id'] ?? 0 );
		$data    = array(
			'entry_date'    => $date,
			'adsense_usd'   => $usd,
			'exchange_rate' => $rate,
			'adspend_krw'   => $adspend,
			'pageviews'     => $pageviews,
		);
		$formats = array( '%s', '%f', '%f', '%f', '%d' );

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			wp_die( '저장을 시작하지 못했습니다. 잠시 후 다시 시도하세요.' );
		}

		$save_ok    = true;
		$save_error = '';
		if ( $edit_id ) {
			$original_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $edit_id ) );
			if ( ! $original_id ) {
				$save_ok    = false;
				$save_error = '수정할 데이터를 찾지 못했습니다.';
			} else {
				$target_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE entry_date = %s FOR UPDATE", $date ) );
				if ( $target_id && (int) $target_id !== $edit_id ) {
					$save_ok    = false;
					$save_error = $date . '에는 이미 저장된 데이터가 있습니다. 해당 날짜의 항목을 직접 수정하세요.';
				} else {
					$save_ok = false !== $wpdb->update( $table, $data, array( 'id' => $edit_id ), $formats, array( '%d' ) );
				}
			}
		} else {
			$target_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE entry_date = %s FOR UPDATE", $date ) );
			$save_ok   = $target_id
				? false !== $wpdb->update( $table, $data, array( 'id' => (int) $target_id ), $formats, array( '%d' ) )
				: false !== $wpdb->insert( $table, $data, $formats );
		}

		if ( ! $save_ok ) {
			$wpdb->query( 'ROLLBACK' );
			wp_die( $save_error ? esc_html( $save_error ) : '저장하지 못했습니다. 기존 데이터는 변경되지 않았습니다.' );
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			wp_die( '저장을 완료하지 못했습니다. 잠시 후 다시 시도하세요.' );
		}

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
		$deleted = $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
		if ( false === $deleted ) {
			wp_die( '삭제하지 못했습니다. 잠시 후 다시 시도하세요.' );
		}
		if ( 0 === $deleted ) {
			wp_die( '삭제할 데이터를 찾지 못했습니다. 이미 삭제되었을 수 있습니다.' );
		}

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

		// 목록: 년·월 선택 (기본 이번 달).
		$cur_year  = (int) $today->format( 'Y' );
		$cur_month = (int) $today->format( 'n' );
		$sel_year  = isset( $_GET['y'] ) ? absint( $_GET['y'] ) : $cur_year;
		$sel_month = isset( $_GET['m'] ) ? absint( $_GET['m'] ) : $cur_month;
		if ( $sel_month < 1 || $sel_month > 12 ) {
			$sel_month = $cur_month;
		}

		$min_date  = $wpdb->get_var( "SELECT MIN(entry_date) FROM {$table}" );
		$min_year  = $min_date ? (int) substr( $min_date, 0, 4 ) : $cur_year;
		if ( $sel_year < $min_year || $sel_year > $cur_year ) {
			$sel_year = $cur_year;
		}

		$list_first = sprintf( '%04d-%02d-01', $sel_year, $sel_month );
		$list_last  = gmdate( 'Y-m-t', strtotime( $list_first ) );
		$month_sum  = $this->card_data( $list_first, $list_last );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE entry_date BETWEEN %s AND %s ORDER BY entry_date DESC",
				$list_first,
				$list_last
			),
			ARRAY_A
		);

		?>
		<div class="wrap apd-wrap">
			<h1>광고수익 대시보드</h1>

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
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
						<?php wp_nonce_field( 'tamsaek_profit_save' ); ?>
						<input type="hidden" name="action" value="tamsaek_profit_save">
						<input type="hidden" name="edit_id" id="apd-edit-id" value="">

						<div class="apd-field">
							<label for="apd-date">날짜</label>
							<input type="date" id="apd-date" name="entry_date" value="" max="<?php echo esc_attr( $yesterday ); ?>" autocomplete="off" required>
							<span class="apd-hint">어제까지만 입력할 수 있습니다 (확정치 기준).</span>
						</div>
						<div class="apd-field">
							<label for="apd-usd">애드센스 수익 ($)</label>
							<input type="number" step="0.01" min="0" id="apd-usd" name="adsense_usd" autocomplete="off" required>
						</div>
						<div class="apd-field">
							<label for="apd-rate">환율 (₩/$)</label>
							<input type="number" step="0.01" min="0" id="apd-rate" name="exchange_rate" value="" autocomplete="off" required>
							<span id="apd-rate-status" class="apd-hint"></span>
						</div>
						<div class="apd-field">
							<label for="apd-adspend">광고비 (₩)</label>
							<input type="number" step="1" min="0" id="apd-adspend" name="adspend_krw" autocomplete="off" required>
						</div>
						<div class="apd-field">
							<label for="apd-rpm">페이지 RPM ($)</label>
							<input type="number" step="0.01" min="0" id="apd-rpm" name="page_rpm" autocomplete="off">
							<span class="apd-hint">애드센스 실적의 "페이지 RPM" 그대로 입력</span>
						</div>

						<p class="apd-hint">같은 날짜로 저장하면 기존 값을 덮어씁니다.</p>
						<button type="submit" class="button button-primary button-large apd-submit">저장</button>
					</form>
				</div>
			</div>

			<div class="apd-list-head">
				<h2 class="apd-list-title">입력 내역</h2>
				<form method="get" class="apd-month-form">
					<input type="hidden" name="page" value="tamsaek-profit">
					<select name="y" onchange="this.form.submit()">
						<?php for ( $y = $cur_year; $y >= $min_year; $y-- ) : ?>
							<option value="<?php echo esc_attr( $y ); ?>" <?php selected( $y, $sel_year ); ?>><?php echo esc_html( $y ); ?>년</option>
						<?php endfor; ?>
					</select>
					<select name="m" onchange="this.form.submit()">
						<?php for ( $mo = 1; $mo <= 12; $mo++ ) : ?>
							<option value="<?php echo esc_attr( $mo ); ?>" <?php selected( $mo, $sel_month ); ?>><?php echo esc_html( $mo ); ?>월</option>
						<?php endfor; ?>
					</select>
					<span class="apd-month-sum">
						순수익 <strong class="<?php echo $month_sum['net'] >= 0 ? 'apd-net-plus' : 'apd-net-minus'; ?>">₩<?php echo esc_html( number_format( $month_sum['net'] ) ); ?></strong>
						· <?php echo esc_html( count( $rows ) ); ?>일
					</span>
				</form>
			</div>
			<table class="apd-table">
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
						<tr><td colspan="8" class="apd-empty"><?php echo esc_html( sprintf( '%d년 %d월에 입력된 데이터가 없습니다.', $sel_year, $sel_month ) ); ?></td></tr>
					<?php endif; ?>
					<?php
					$weekdays = array( '일요일', '월요일', '화요일', '수요일', '목요일', '금요일', '토요일' );
					foreach ( $rows as $r ) :
						$usd          = floatval( $r['adsense_usd'] );
						$rate         = floatval( $r['exchange_rate'] );
						$krw          = $usd * $rate;
						$net          = $krw - floatval( $r['adspend_krw'] );
						$rpm          = $r['pageviews'] > 0 ? $usd / $r['pageviews'] * 1000 : 0;
						$row_date     = DateTimeImmutable::createFromFormat( '!Y-m-d', $r['entry_date'], $tz );
						$date_display = $row_date ? $row_date->format( 'Y. m. d' ) : $r['entry_date'];
						$weekday      = $row_date ? $weekdays[ (int) $row_date->format( 'w' ) ] : '';
						$del = wp_nonce_url(
							admin_url( 'admin-post.php?action=tamsaek_profit_delete&id=' . absint( $r['id'] ) ),
							'tamsaek_profit_delete_' . absint( $r['id'] )
						);
						?>
						<tr>
							<td data-label="날짜">
								<span class="apd-date-main"><?php echo esc_html( $date_display ); ?></span>
								<?php if ( $weekday ) : ?><span class="apd-date-day"><?php echo esc_html( $weekday ); ?></span><?php endif; ?>
							</td>
							<td data-label="애드센스 ($)">$<?php echo esc_html( number_format( $usd, 2 ) ); ?></td>
							<td data-label="환율"><?php echo esc_html( number_format( $rate, 2 ) ); ?></td>
							<td data-label="애드센스 (₩)">₩<?php echo esc_html( number_format( $krw ) ); ?></td>
							<td data-label="광고비 (₩)">₩<?php echo esc_html( number_format( floatval( $r['adspend_krw'] ) ) ); ?></td>
							<td data-label="순수익 (₩)"><strong class="<?php echo $net >= 0 ? 'apd-net-plus' : 'apd-net-minus'; ?>">₩<?php echo esc_html( number_format( $net ) ); ?></strong></td>
							<td data-label="RPM ($)">$<?php echo esc_html( number_format( $rpm, 2 ) ); ?></td>
							<td class="apd-col-actions" data-label="관리">
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

		</div>

		<style>
			.apd-wrap {
				max-width: 1200px;
				font-family: "Pretendard Variable", Pretendard, -apple-system, BlinkMacSystemFont, "Segoe UI", "Noto Sans KR", "Malgun Gothic", sans-serif;
				font-size: 14px;
				letter-spacing: -.15px;
			}
			.apd-wrap input,
			.apd-wrap select,
			.apd-wrap button,
			.apd-wrap textarea,
			.apd-wrap table {
				font: inherit;
			}
			.apd-wrap input,
			.apd-wrap select,
			.apd-wrap button { letter-spacing: inherit; }
			.apd-wrap strong,
			.apd-wrap .apd-card__net,
			.apd-wrap .apd-table td { font-variant-numeric: tabular-nums; }
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

			.apd-list-head { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; margin-top: 34px; }
			.apd-list-title { margin: 0; }
			.apd-month-form { display: flex; align-items: center; gap: 8px; }
			.apd-month-form select { border-radius: 8px; }
			.apd-month-sum { color: #475467; font-size: 14px; margin-left: 4px; }
			.apd-month-sum strong { font-size: 15px; }
			.apd-table {
				display: block;
				border: 0;
				box-shadow: none;
				background: transparent;
				margin-top: 12px;
			}
			.apd-table thead { display: none; }
			.apd-table tbody {
				display: grid;
				grid-template-columns: repeat(3, minmax(0, 1fr));
				gap: 14px;
			}
			@media (max-width: 1100px) {
				.apd-table tbody { grid-template-columns: repeat(2, minmax(0, 1fr)); }
			}
			.apd-table tr {
				display: grid;
				grid-template-columns: repeat(4, minmax(0, 1fr));
				align-items: start;
				gap: 0;
				overflow: hidden;
				background: #fff;
				border: 0;
				border-radius: 16px;
				box-shadow: 0 5px 16px rgba(23, 60, 45, .055);
			}
			.apd-table td {
				display: block;
				border: 0;
				padding: 12px 14px;
				min-width: 0;
				font-size: 14px;
				color: #101828;
			}
			.apd-table td::before {
				display: block;
				margin-bottom: 4px;
				color: #89958e;
				font-size: 11px;
				font-weight: 500;
				content: attr(data-label);
			}
			.apd-table td[data-label="날짜"] {
				order: 1;
				grid-column: 1 / 3;
				padding: 16px 16px 14px;
				border-bottom: 1px solid #f2f4f3;
				font-size: 16px;
				font-weight: 800;
			}
			.apd-table td[data-label="날짜"]::before { content: "날짜"; }
			.apd-date-main { display: block; }
			.apd-date-day {
				display: block;
				margin-top: 4px;
				color: #89958e;
				font-size: 12px;
				font-weight: 500;
			}
			.apd-table td[data-label="순수익 (₩)"] {
				order: 2;
				grid-column: 3 / 5;
				padding: 14px 16px 12px;
				border-bottom: 1px solid #f2f4f3;
				text-align: right;
			}
			.apd-table td[data-label="순수익 (₩)"]::before { content: "순수익"; }
			.apd-table td[data-label="순수익 (₩)"] strong {
				display: block;
				font-size: 24px;
				font-weight: 800;
				line-height: 1.15;
				letter-spacing: -.5px;
			}
			.apd-table td[data-label="애드센스 ($)"] { order: 3; grid-column: span 2; }
			.apd-table td[data-label="환율"] { order: 4; grid-column: span 2; }
			.apd-table td[data-label="애드센스 (₩)"] { order: 5; grid-column: span 2; }
			.apd-table td[data-label="광고비 (₩)"] { order: 6; grid-column: span 2; }
			.apd-table td[data-label="RPM ($)"] {
				order: 7;
				grid-column: 1 / 3;
				border-top: 1px solid #f0f3f1;
				color: #1d6ae5;
				font-weight: 800;
			}
			.apd-table td.apd-col-actions {
				order: 8;
				grid-column: 3 / 5;
				border-top: 1px solid #f0f3f1;
				text-align: right;
			}
			.apd-table td.apd-col-actions::before { display: none; }
			.apd-table td.apd-empty { grid-column: 1 / -1; text-align: center; color: #98a2b3; }
			.apd-table td.apd-empty::before { display: none; }
			.apd-net-plus { color: #139958; }
			.apd-net-minus { color: #e5484d; }
			.apd-col-actions { text-align: right; white-space: nowrap; }
			.apd-sep { color: #d0d5dd; margin: 0 2px; }
			.apd-edit { color: #1d6ae5; text-decoration: none; }
			.apd-delete { color: #b91c1c; text-decoration: none; }

			/* 모바일: 날짜와 순수익을 강조한 독립 카드 */
			@media (max-width: 782px) {
				.apd-list-head { align-items: flex-start; }
				.apd-month-form { flex-wrap: wrap; }
				.apd-month-sum { flex: 0 0 100%; margin-left: 0; }
				.apd-table tbody { grid-template-columns: 1fr; gap: 12px; }
				.apd-table tr {
					grid-template-columns: repeat(2, minmax(0, 1fr));
				}
				.apd-table td[data-label="날짜"] {
					grid-column: 1;
					font-size: 15px;
				}
				.apd-table td[data-label="순수익 (₩)"] {
					grid-column: 2;
				}
				.apd-table td[data-label="순수익 (₩)"] strong {
					font-size: 27px;
				}
				.apd-table td[data-label="애드센스 ($)"],
				.apd-table td[data-label="환율"],
				.apd-table td[data-label="애드센스 (₩)"],
				.apd-table td[data-label="광고비 (₩)"] {
					grid-column: span 1;
				}
				.apd-table td[data-label="RPM ($)"] { grid-column: 1; }
				.apd-table td.apd-col-actions { grid-column: 2; }
				.apd-table td.apd-empty { grid-column: 1 / -1; text-align: center; }
			}
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
				dateInput.value = '';
				usdInput.value = '';
				rateInput.value = '';
				spendIn.value = '';
				rpmInput.value = '';
				status.textContent = '';
				lastFetched = '';
				editNote.hidden = true;
				editNote.textContent = '';
			}
			window.addEventListener('pageshow', clearEditMode);

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
