<?php
/**
 * 院校详情页（自定义端点 /schools/{slug}/）。
 *
 * 由 inc/schools.php 的 template_include 加载。
 * 未发布或不存在的院校在 template_redirect 阶段已被置为 404，不会走到这里。
 *
 * SEO：唯一 H1（院校名）、逐校独立 description、canonical 指向本页、
 *      面包屑 + BreadcrumbList、WebPage/about 结构化数据（见 inc/schools.php）。
 *
 * 内容准确性：学费一律以「目安」呈现。这些数据来自院校库，
 * 发布开关默认关闭，须业务方核实后逐校开启，详见 inc/schools.php 顶部说明。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sa_school = sa_current_school();

if ( ! $sa_school ) {
	// 兜底：正常流程不会到这里（template_redirect 已置 404）。
	get_header();
	echo '<main class="sa-section"><div class="sa-container"><p>'
		. esc_html__( 'コンテンツが見つかりませんでした。', 'sa-theme' )
		. '</p></div></main>';
	get_footer();
	return;
}

$sa_name     = sa_school_name( $sa_school );
$sa_desc     = sa_school_description( $sa_school );
$sa_programs = class_exists( 'SA_School_Repo' ) ? SA_School_Repo::get_school_programs( $sa_school['id'] ) : array();
$sa_docs     = class_exists( 'SA_School_Repo' ) ? SA_School_Repo::get_required_docs( $sa_school['id'] ) : array();
$sa_type     = sa_school_type_label( isset( $sa_school['school_type'] ) ? $sa_school['school_type'] : '' );

// 未发布院校的管理员预览：明确标示，避免误以为已对外可见。
$sa_is_preview = empty( $sa_school['published'] );

get_header();
?>

<div class="sa-page-head">
	<div class="sa-container">
		<?php
		sa_breadcrumb(
			array(
				array( __( 'ホーム', 'sa-theme' ), sa_home_url( '/' ) ),
				array( __( '学校情報', 'sa-theme' ), sa_schools_url() ),
				array( $sa_name, '' ),
			)
		);
		?>

		<?php if ( $sa_is_preview ) : ?>
			<p class="sa-preview-notice">
				<?php esc_html_e( '【未公開プレビュー】この院校ページはまだ公開されていません。管理者のみ閲覧できます。', 'sa-theme' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( '' !== $sa_type ) : ?>
			<span class="sa-school-type-badge"><?php echo esc_html( $sa_type ); ?></span>
		<?php endif; ?>

		<h1 class="sa-page-head__title"><?php echo esc_html( $sa_name ); ?></h1>

		<?php if ( ! empty( $sa_school['region'] ) || ! empty( $sa_school['city'] ) ) : ?>
			<p class="sa-page-head__sub">
				<?php
				echo esc_html(
					implode( ' ', array_filter( array( $sa_school['region'], $sa_school['city'] ) ) )
				);
				?>
			</p>
		<?php endif; ?>

		<?php
		// 冒頭には 1 行版のみ。全文は本文末尾（CTA 直前）に置く。
		get_template_part(
			'template-parts/relationship-disclosure',
			null,
			array( 'variant' => 'compact' )
		);
		?>
	</div>
</div>

<section class="sa-section">
	<div class="sa-container sa-school-detail">

		<!-- 基本情報 -->
		<h2 class="sa-school-detail__h2"><?php esc_html_e( '基本情報', 'sa-theme' ); ?></h2>
		<div class="sa-school-facts">
			<dl>
				<?php if ( '' !== $sa_type ) : ?>
					<div><dt><?php esc_html_e( '学校種別', 'sa-theme' ); ?></dt><dd><?php echo esc_html( $sa_type ); ?></dd></div>
				<?php endif; ?>

				<?php if ( ! empty( $sa_school['region'] ) ) : ?>
					<div><dt><?php esc_html_e( '地域', 'sa-theme' ); ?></dt><dd><?php echo esc_html( $sa_school['region'] ); ?></dd></div>
				<?php endif; ?>

				<?php if ( ! empty( $sa_school['city'] ) ) : ?>
					<div><dt><?php esc_html_e( '所在地', 'sa-theme' ); ?></dt><dd><?php echo esc_html( $sa_school['city'] ); ?></dd></div>
				<?php endif; ?>

				<?php if ( ! empty( $sa_school['language_req'] ) ) : ?>
					<div><dt><?php esc_html_e( '語学要件', 'sa-theme' ); ?></dt><dd><?php echo esc_html( $sa_school['language_req'] ); ?></dd></div>
				<?php endif; ?>

				<?php if ( ! empty( $sa_school['min_education'] ) ) : ?>
					<div><dt><?php esc_html_e( '最低学歴', 'sa-theme' ); ?></dt><dd><?php echo esc_html( sa_min_education_label( $sa_school['min_education'] ) ); ?></dd></div>
				<?php endif; ?>

				<?php
				/*
				 * 学校公式サイトへの導線。
				 *
				 * 本ページの学費・要件は各校の公表資料をまとめた参考情報にすぎず、
				 * 当サイトは代理店でもないため、一次情報への経路を必ず示す。
				 * 「最新情報は公式で」と書きながらリンクを出さないのは不親切なだけでなく、
				 * 情報の出所を確認できないページとして品質評価上も不利になる。
				 *
				 * rel: nofollow は付けない。これは広告でも有料リンクでもなく、
				 * 一次情報への正当な引用リンク。
				 */
				$sa_official = isset( $sa_school['official_url'] ) ? trim( (string) $sa_school['official_url'] ) : '';
				if ( '' !== $sa_official ) :
					?>
					<div>
						<dt><?php esc_html_e( '学校公式サイト', 'sa-theme' ); ?></dt>
						<dd>
							<a href="<?php echo esc_url( $sa_official ); ?>" target="_blank" rel="noopener">
								<?php echo esc_html( preg_replace( '#^https?://#', '', untrailingslashit( $sa_official ) ) ); ?>
								<span class="screen-reader-text"><?php esc_html_e( '（外部サイト・新しいタブで開きます）', 'sa-theme' ); ?></span>
							</a>
						</dd>
					</div>
				<?php endif; ?>
			</dl>
		</div>

		<!-- 学校紹介 -->
		<?php if ( '' !== trim( wp_strip_all_tags( $sa_desc ) ) ) : ?>
			<h2 class="sa-school-detail__h2"><?php esc_html_e( '学校紹介', 'sa-theme' ); ?></h2>
			<div class="sa-card__text sa-school-detail__body">
				<?php echo wp_kses_post( wpautop( $sa_desc ) ); ?>
			</div>
		<?php endif; ?>

		<!-- 募集コース -->
		<?php
		if ( ! empty( $sa_programs ) ) :
			/*
			 * 学費を公表していない学校がある。
			 *
			 * 日本語学校の中には、学費を募集要項の PDF にのみ記載し、
			 * ウェブページには載せていないところが実在する。その場合に
			 * 「学費（目安）」という列を出して中身を全部「—」にすると、
			 * 見出しだけあって情報がない状態になり、かえって不親切になる。
			 *
			 * そこで、その学校のコースが一つも金額を持たないときは学費列ごと
			 * 落とし、代わりに学校公式サイトで確認するよう促す。
			 * 校名・コース・語学要件・修業年限といった確認済みの事実は
			 * そのまま残るので、ページとしては十分に成立する。
			 */
			// 上の基本情報ブロックで代入済みだが、ブロックの並び替えで
			// 未定義になると致命的エラーになるため、ここでも取り直す。
			$sa_official = isset( $sa_school['official_url'] ) ? trim( (string) $sa_school['official_url'] ) : '';

			$sa_has_tuition = false;
			foreach ( $sa_programs as $sa_p ) {
				if ( (int) $sa_p['tuition_min'] > 0 || (int) $sa_p['tuition_max'] > 0 ) {
					$sa_has_tuition = true;
					break;
				}
			}
			?>
			<h2 class="sa-school-detail__h2">
				<?php
				echo $sa_has_tuition
					? esc_html__( '募集コース・学費目安', 'sa-theme' )
					: esc_html__( '募集コース', 'sa-theme' );
				?>
			</h2>
			<div class="sa-table-wrap">
				<table class="sa-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'コース', 'sa-theme' ); ?></th>
							<?php if ( $sa_has_tuition ) : ?>
								<th><?php esc_html_e( '学費（目安）', 'sa-theme' ); ?></th>
							<?php endif; ?>
							<th><?php esc_html_e( '語学要件', 'sa-theme' ); ?></th>
							<th><?php esc_html_e( '修業年限', 'sa-theme' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sa_programs as $sa_p ) : ?>
							<tr>
								<td><?php echo esc_html( sa_program_name( $sa_p ) ); ?></td>
								<?php if ( $sa_has_tuition ) : ?>
									<td>
										<?php
										$sa_t = sa_tuition_range(
											$sa_p['tuition_min'],
											$sa_p['tuition_max'],
											isset( $sa_p['tuition_basis'] ) ? $sa_p['tuition_basis'] : 'year'
										);
										echo '' !== $sa_t ? esc_html( $sa_t ) : '—';

										// 该金额含哪些费用因校而异，通用脚注说不清楚，逐条给。
										if ( ! empty( $sa_p['tuition_note'] ) ) {
											echo '<br><small class="sa-tuition-note">'
												. esc_html( $sa_p['tuition_note'] ) . '</small>';
										}
										?>
									</td>
								<?php endif; ?>
								<td><?php echo ! empty( $sa_p['language_req'] ) ? esc_html( $sa_p['language_req'] ) : '—'; ?></td>
								<td><?php echo ! empty( $sa_p['duration'] ) ? esc_html( $sa_p['duration'] ) : '—'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="sa-note">
				<?php
				if ( $sa_has_tuition ) {
					// 「入学金・教材費は別途」と一律に書くのは誤り ——
					// 総額に含めて公表している学校もある（含む／含まないは各行の注記で示す）。
					esc_html_e( '※ 学費は目安です。金額に含まれる費用は学校・コースにより異なります（各行の注記をご確認ください）。最新の金額は必ず学校公式サイトの募集要項でご確認ください。', 'sa-theme' );
				} else {
					// 金額を持たないことを曖昧にせず、はっきり書いて公式サイトへ送る。
					esc_html_e( '※ この学校は学費をウェブサイト上で公開していないため、当ページには掲載していません。学費は学校公式サイトの募集要項、または学校へ直接お問い合わせのうえご確認ください。', 'sa-theme' );
				}

				if ( '' !== $sa_official ) {
					echo '<br><a href="' . esc_url( $sa_official ) . '" target="_blank" rel="noopener">'
						. esc_html__( '学校公式サイトで確認する', 'sa-theme' )
						. '<span class="screen-reader-text">'
						. esc_html__( '（外部サイト・新しいタブで開きます）', 'sa-theme' )
						. '</span></a>';
				}
				?>
			</p>
		<?php endif; ?>

		<!-- 出願に必要な書類 -->
		<?php if ( ! empty( $sa_docs ) ) : ?>
			<h2 class="sa-school-detail__h2"><?php esc_html_e( '出願に必要な書類', 'sa-theme' ); ?></h2>
			<ul class="sa-doc-list">
				<?php foreach ( $sa_docs as $sa_doc ) : ?>
					<?php if ( empty( $sa_doc['label'] ) ) { continue; } ?>
					<li>
						<?php echo esc_html( $sa_doc['label'] ); ?>
						<?php if ( ! empty( $sa_doc['required'] ) ) : ?>
							<span class="sa-doc-list__req"><?php esc_html_e( '必須', 'sa-theme' ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="sa-note">
				<?php esc_html_e( '※ 学歴や国籍により追加書類を求められる場合があります。詳細は無料相談でご案内します。', 'sa-theme' ); ?>
			</p>
		<?php endif; ?>

		<?php
		/*
		 * 関係性の開示は本文の最後、CTA の直前に置く。
		 *
		 * ページ冒頭に全文を置くとファーストビューが説明文で埋まり、
		 * 学校情報という本来のコンテンツが押し下げられる。
		 * 一方で CTA（＝申し込み導線）より後ろに回すと、
		 * 申し込みを検討する時点で読まれない可能性がある。
		 * よって「本文の締め、かつ CTA の直前」が唯一妥当な位置。
		 * ページ冒頭には別途 compact 版の 1 行を出している。
		 */
		get_template_part(
			'template-parts/relationship-disclosure',
			null,
			array( 'variant' => 'full' )
		);
		?>

		<!-- 内链：回列表 -->
		<p class="sa-school-detail__back">
			<a href="<?php echo esc_url( sa_schools_url() ); ?>">← <?php esc_html_e( '学校情報一覧に戻る', 'sa-theme' ); ?></a>
		</p>
	</div>
</section>

<section class="sa-cta-band">
	<div class="sa-container">
		<h2>
			<?php
			/* translators: %s: 院校名 */
			printf( esc_html__( '%s に出願できるか、無料で診断', 'sa-theme' ), esc_html( $sa_name ) );
			?>
		</h2>
		<p><?php esc_html_e( '予算・日本語レベル・希望専攻を入力するだけ。マッチ度をその場で確認できます。', 'sa-theme' ); ?></p>
		<a href="<?php echo esc_url( sa_home_url( '/#lead-form' ) ); ?>" class="sa-btn sa-btn--primary sa-btn--lg" data-sa-cta="school-detail-cta">
			<?php esc_html_e( '無料でAI診断を受ける', 'sa-theme' ); ?>
		</a>
	</div>
</section>

<?php
get_footer();
