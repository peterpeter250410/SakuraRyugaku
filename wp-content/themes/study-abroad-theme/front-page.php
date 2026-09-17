<?php
/**
 * 落地页（首页）— 市场验证核心转化载体。
 *
 * 结构：Hero + 意向表单 → 卖点 → 流程 → 信任背书 → FAQ(带 Schema) → CTA。
 * 埋点：data-sa-lp 落地页视图、data-sa-form 表单曝光/开始、data-sa-cta 各 CTA。
 *
 * @package StudyAbroadTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 落地页版本（供 A/B 与埋点归因）。
$lp_variant = 'default';

// FAQ 数据（同时用于展示与结构化数据）。
$faqs = array(
	array(
		'q' => __( '日本留学の相談は無料ですか？', 'sa-theme' ),
		'a' => __( 'はい。学校マッチングと初回相談は無料です。まずはお気軽に意向情報をご入力ください。', 'sa-theme' ),
	),
	array(
		'q' => __( '日本語ができなくても留学できますか？', 'sa-theme' ),
		'a' => __( '語学学校からのスタートも可能です。あなたのレベルと目標に合わせて最適な進学ルートをご提案します。', 'sa-theme' ),
	),
	array(
		'q' => __( '予算に合う学校を紹介してもらえますか？', 'sa-theme' ),
		'a' => __( 'ご予算と希望専攻をもとに、システムが自動で候補校をマッチングします。', 'sa-theme' ),
	),
	array(
		'q' => __( '入力した個人情報は安全ですか？', 'sa-theme' ),
		'a' => __( 'お預かりする情報は暗号化して安全に管理し、プライバシーポリシーに従って取り扱います。', 'sa-theme' ),
	),
);

get_header();
?>
<main id="sa-main">

<!-- 落地页视图埋点标记 -->
<div data-sa-lp="<?php echo esc_attr( $lp_variant ); ?>"></div>

<!-- ============ HERO + 表单 ============ -->
<section class="sa-hero">
	<?php
	/*
	 * 首屏大图。此前是 .sa-hero 的 CSS background-image，现改为真正的 <img>。
	 *
	 * 动机是 LCP：这张图就是 LCP 元素，而 CSS 背景图对 LCP 有两个结构性劣势 ——
	 * preload scanner 只扫 HTML 扫不到 CSS 里的 url()，且背景图的加载优先级
	 * 低于 <img>。原先靠在 <head> 里手写三条 media 分档的 preload 绕开前者，
	 * 那是给结构性问题打补丁，还得让 preload 的断点与 CSS 媒体查询逐条对齐。
	 *
	 * 改成 <img> 之后：档位交给 srcset + sizes，浏览器自己按视口挑，
	 * 不必再维护两套断点；preload scanner 原生就能发现它；优先级用
	 * fetchpriority="high" 直接指定。手写的 preload 已一并移除，
	 * 否则它选中的档位可能与 srcset 选出的不同，变成下载两张图。
	 *
	 * alt 留空 + aria-hidden：这是纯装饰性底图，内容全在它上层的文字里，
	 * 让读屏软件念一遍图片描述只会干扰。
	 *
	 * 不写 loading 属性（即默认 eager）：首屏图片绝不能懒加载。
	 */
	$sa_hero_base = get_template_directory_uri() . '/assets/images/';
	?>
	<div class="sa-hero__bg" aria-hidden="true">
		<picture>
			<?php
			/*
			 * 手机固定用 640w，通过 <source media> 而不是靠 srcset 自选。
			 *
			 * 上一版只写了 srcset + sizes="100vw"，结果手机端反而比改版前更重。
			 * 浏览器选档看的是 sizes × 设备像素比：
			 *     412 CSS px（手机视口）× DPR 1.75 = 721 设备像素
			 *     候选 640 / 1280 / 1920 里取 ≥721 的最小者 → 1280w
			 * 而 412 × 1.56 就已超过 640，也就是说 DPR ≥ 1.56 的设备一律选 1280w，
			 * 现代手机全在 2~3 之间。实测体积：640w 34.7 KB、1280w 87.5 KB ——
			 * 等于把 LCP 资源放大了 2.5 倍。
			 *
			 * 改版前是 CSS 的 @media (max-width: 640px) 强制取 640w，没有这个问题。
			 * 那条规则的理由依然成立：这张图上压着一层不透明度 .92 的渐变遮罩，
			 * 细节本来就看不清，为它多花 53 KB 不划算。
			 *
			 * sizes 受 DPR 影响、media 不受 —— 想表达「小屏就用这一档」，
			 * <picture> 的 media 才是对的工具，写成 sizes="…360px…" 去凑
			 * 则是在谎报布局宽度。
			 */
			?>
			<source
				media="(max-width: 640px)"
				type="image/webp"
				srcset="<?php echo esc_url( $sa_hero_base . 'hero-bg-640w.webp' ); ?>">
			<source
				media="(max-width: 640px)"
				srcset="<?php echo esc_url( $sa_hero_base . 'hero-bg-640w.jpg' ); ?>">
			<source
				type="image/webp"
				srcset="<?php echo esc_url( $sa_hero_base . 'hero-bg-1280w.webp' ); ?> 1280w,
				        <?php echo esc_url( $sa_hero_base . 'hero-bg-1920w.webp' ); ?> 1920w"
				sizes="100vw">
			<?php
			// 兜底的 <img>：只有当上面所有 <source> 都不匹配时才用它，
			// 因此这里不再列 640w —— 那一档已由 media 的 source 负责。
			?>
			<img src="<?php echo esc_url( $sa_hero_base . 'hero-bg-1280w.jpg' ); ?>"
				srcset="<?php echo esc_url( $sa_hero_base . 'hero-bg-1280w.jpg' ); ?> 1280w,
				        <?php echo esc_url( $sa_hero_base . 'hero-bg-1920w.jpg' ); ?> 1920w"
				sizes="100vw"
				alt=""
				width="1920" height="1080"
				fetchpriority="high"
				decoding="async">
		</picture>
	</div>
	<div class="sa-container sa-hero__inner">
		<div class="sa-hero__copy">
			<span class="sa-hero__badge"><?php esc_html_e( '無料・最短即日マッチング', 'sa-theme' ); ?></span>
			<h1 class="sa-hero__title">
				<?php
				/* translators: 强调词用 <em> 包裹 */
				echo wp_kses_post( __( '日本留学を、<em>最適な一校</em>から始めよう', 'sa-theme' ) );
				?>
			</h1>
			<p class="sa-hero__sub"><?php esc_html_e( '予算と希望専攻を入力するだけ。あなたに合った日本の学校を無料でご提案します。', 'sa-theme' ); ?></p>
			<ul class="sa-hero__points">
				<li><?php esc_html_e( '無料で学校マッチング', 'sa-theme' ); ?></li>
				<li><?php esc_html_e( '多言語サポート', 'sa-theme' ); ?></li>
				<li><?php esc_html_e( '出願書類サポート', 'sa-theme' ); ?></li>
				<li><?php esc_html_e( '専任アドバイザー', 'sa-theme' ); ?></li>
			</ul>
		</div>

		<!-- 核心转化表单 -->
		<div class="sa-form-card" id="lead-form">
			<h2 class="sa-form-card__title"><?php esc_html_e( '無料AI診断で学校マッチング', 'sa-theme' ); ?></h2>
			<p class="sa-form-card__sub"><?php esc_html_e( '30秒で入力完了。AIがその場で最適な学校を診断します。', 'sa-theme' ); ?></p>

			<form class="sa-lead-form" data-sa-form="landing-hero" novalidate>
				<div class="sa-field">
					<label for="sa-name"><?php esc_html_e( 'お名前', 'sa-theme' ); ?> <span aria-hidden="true">*</span></label>
					<input type="text" id="sa-name" name="name" required autocomplete="name">
				</div>

				<div class="sa-field">
					<label for="sa-contact-type"><?php esc_html_e( '連絡方法', 'sa-theme' ); ?></label>
					<select id="sa-contact-type" name="contact_type">
						<option value="email"><?php esc_html_e( 'メール', 'sa-theme' ); ?></option>
						<option value="line">LINE</option>
						<option value="wechat"><?php esc_html_e( 'WeChat / 微信', 'sa-theme' ); ?></option>
						<option value="whatsapp">WhatsApp</option>
						<option value="phone"><?php esc_html_e( '電話', 'sa-theme' ); ?></option>
					</select>
				</div>

				<div class="sa-field">
					<label for="sa-contact"><?php esc_html_e( '連絡先', 'sa-theme' ); ?> <span aria-hidden="true">*</span></label>
					<input type="text" id="sa-contact" name="contact_value" required
						placeholder="<?php esc_attr_e( 'メール / LINE ID / 電話番号', 'sa-theme' ); ?>">
				</div>

				<div class="sa-field sa-field--row">
					<div>
						<label for="sa-budget"><?php esc_html_e( '年間予算（万円）', 'sa-theme' ); ?></label>
						<select id="sa-budget" name="budget_range">
							<option value=""><?php esc_html_e( '選択してください', 'sa-theme' ); ?></option>
							<option value="0-80"><?php esc_html_e( '〜80万', 'sa-theme' ); ?></option>
							<option value="80-120">80〜120<?php esc_html_e( '万', 'sa-theme' ); ?></option>
							<option value="120-200">120〜200<?php esc_html_e( '万', 'sa-theme' ); ?></option>
							<option value="200-9999"><?php esc_html_e( '200万〜', 'sa-theme' ); ?></option>
						</select>
					</div>
					<div>
						<label for="sa-major"><?php esc_html_e( '希望専攻', 'sa-theme' ); ?></label>
						<input type="text" id="sa-major" name="intended_major"
							placeholder="<?php esc_attr_e( '例：経営 / IT / 文学', 'sa-theme' ); ?>">
					</div>
				</div>

				<!-- 蜜罐字段（防机器人，用户不可见） -->
				<div class="sa-honeypot" aria-hidden="true">
					<label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
				</div>

				<label class="sa-consent">
					<input type="checkbox" name="consent" value="1">
					<span><?php
						printf(
							/* translators: %s: privacy policy link */
							esc_html__( '%s に同意します。', 'sa-theme' ),
							'<a href="' . esc_url( sa_home_url( '/privacy/' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'プライバシーポリシー', 'sa-theme' ) . '</a>'
						);
					?></span>
				</label>

				<button type="submit" class="sa-btn sa-btn--primary sa-btn--block sa-btn--lg" data-sa-cta="form-submit">
					<?php esc_html_e( '無料でAI診断を受ける', 'sa-theme' ); ?>
				</button>

				<div class="sa-form-msg" role="status" aria-live="polite"></div>
			</form>

			<!-- AI 诊断结果即时展示区（同页弹出，不跳转） -->
			<div class="sa-diagnose-result" data-sa-diagnose-result hidden></div>
		</div>
	</div>
</section>

<!-- ============ 卖点 ============ -->
<section class="sa-section" id="services">
	<div class="sa-container">
		<div class="sa-section__head">
			<span class="sa-section__tag">Why Us</span>
			<h2 class="sa-section__title"><?php esc_html_e( '選ばれる理由', 'sa-theme' ); ?></h2>
			<p class="sa-section__desc"><?php esc_html_e( '留学の「わからない」を、データとプロの力で解決します。', 'sa-theme' ); ?></p>
		</div>
		<div class="sa-grid sa-grid--4">
			<?php
			// 注意：翻译函数必须接收字面量字符串，gettext 才能提取。
			// 此前写作 esc_html__( $f[0], ... ) 传入变量，导致这些文案永远不会被翻译。
			$features = array(
				array( __( '無料マッチング', 'sa-theme' ), __( '予算と希望専攻から、最適な学校を自動でご提案。', 'sa-theme' ) ),
				array( __( '多言語サポート', 'sa-theme' ), __( '母国語で安心して相談。多言語対応を順次拡大中。', 'sa-theme' ) ),
				array( __( '出願書類サポート', 'sa-theme' ), __( '複雑な出願手続きを、専任スタッフがサポート。', 'sa-theme' ) ),
				array( __( '安心の情報管理', 'sa-theme' ), __( '個人情報は暗号化して安全に管理します。', 'sa-theme' ) ),
			);
			$i = 0;
			foreach ( $features as $f ) {
				$i++;
				echo '<div class="sa-card">';
				echo '<div class="sa-card__icon">0' . esc_html( $i ) . '</div>';
				echo '<h3 class="sa-card__title">' . esc_html( $f[0] ) . '</h3>';
				echo '<p class="sa-card__text">' . esc_html( $f[1] ) . '</p>';
				echo '</div>';
			}
			?>
		</div>
	</div>
</section>

<!-- ============ 流程 ============ -->
<section class="sa-section sa-section--soft" id="flow">
	<div class="sa-container">
		<div class="sa-section__head">
			<span class="sa-section__tag">Flow</span>
			<h2 class="sa-section__title"><?php esc_html_e( 'ご利用の流れ', 'sa-theme' ); ?></h2>
		</div>
		<div class="sa-steps">
			<?php
			$steps = array(
				array( __( '情報入力', 'sa-theme' ), __( '予算・希望専攻など基本情報を入力。', 'sa-theme' ) ),
				array( __( '無料マッチング', 'sa-theme' ), __( 'システムが最適な候補校をご提案。', 'sa-theme' ) ),
				array( __( '学校を選ぶ', 'sa-theme' ), __( '気になる学校を選択して相談。', 'sa-theme' ) ),
				array( __( '出願サポート', 'sa-theme' ), __( '書類準備から出願までサポート。', 'sa-theme' ) ),
			);
			$n = 0;
			foreach ( $steps as $s ) {
				$n++;
				echo '<div class="sa-step">';
				echo '<div class="sa-step__num">' . esc_html( $n ) . '</div>';
				echo '<div class="sa-step__title">' . esc_html( $s[0] ) . '</div>';
				echo '<div class="sa-step__text">' . esc_html( $s[1] ) . '</div>';
				echo '</div>';
			}
			?>
		</div>
	</div>
</section>

<!-- ============ 進学先の選択肢（旧「信任背书」）============
     提携関係が存在しない以上、ここは「信頼の裏付け」ではなく
     単なる進学先カテゴリの紹介。Trusted タグも外す。 -->
<section class="sa-section">
	<div class="sa-container">
		<div class="sa-section__head">
			<span class="sa-section__tag">Schools</span>
			<h2 class="sa-section__title"><?php esc_html_e( '進学先の選択肢', 'sa-theme' ); ?></h2>
			<p class="sa-section__desc"><?php esc_html_e( '語学学校・専門学校・大学・大学院まで、日本の主な進学先の情報をまとめています。掲載校は順次追加中です。', 'sa-theme' ); ?></p>
		</div>

		<!-- キャンパス・留学生活のイメージ（原生轮播，无依赖） -->
		<?php
		// alt 文案具体化：描述图片内容而非泛指「イメージ」，利于图片搜索收录。
		$sa_slides = array(
			array( 'file' => 'slide-1.jpg', 'alt' => __( '日本の大学キャンパスと留学生', 'sa-theme' ) ),
			array( 'file' => 'slide-2.jpg', 'alt' => __( '日本語学校で学ぶ留学生', 'sa-theme' ) ),
			array( 'file' => 'slide-3.jpg', 'alt' => __( '日本での留学生活の様子', 'sa-theme' ) ),
		);

		$sa_img_dir  = get_template_directory() . '/assets/images/';
		$sa_img_base = get_template_directory_uri() . '/assets/images/';

		/*
		 * 只渲染真实存在的图片：缺图时输出 <img> 会产生 404 请求与破图，
		 * 既损害用户体验，也是负面的页面质量信号。
		 *
		 * 检查的是派生文件（-960w.jpg）而不是源图 —— 页面引用的是
		 * scripts/optimize-images.php 按显示尺寸生成的那些。源图存在
		 * 但没跑过优化脚本时，检查源图会放行，结果输出一堆 404。
		 * JPEG 作为 <picture> 的兜底必定会被请求，所以以它为准即可。
		 */
		$sa_slides = array_values(
			array_filter(
				$sa_slides,
				function ( $slide ) use ( $sa_img_dir ) {
					$stem = pathinfo( $slide['file'], PATHINFO_FILENAME );
					return file_exists( $sa_img_dir . $stem . '-960w.jpg' );
				}
			)
		);

		if ( ! empty( $sa_slides ) ) :
			?>
		<div class="sa-carousel" data-sa-carousel aria-label="<?php esc_attr_e( '留学イメージ', 'sa-theme' ); ?>">
			<div class="sa-carousel__viewport">
				<div class="sa-carousel__track">
					<?php foreach ( $sa_slides as $sa_idx => $slide ) : ?>
						<div class="sa-carousel__slide">
							<?php
							// 首帧属首屏内容：eager + 高优先级，避免拖慢 LCP；
							// 其余帧懒加载。width/height 声明用于预留空间，抑制 CLS。
							$sa_is_first = ( 0 === $sa_idx );
							$sa_stem     = pathinfo( $slide['file'], PATHINFO_FILENAME );

							/*
							 * 尺寸必须与 CSS 的实际显示框一致：
							 *   .sa-carousel 最大宽 960px、img 高 380px（object-fit: cover）
							 *
							 * 此前这里写的是 width="1200" height="675"，而源图其实是
							 * 1200x900 —— 声明的比例（16:9）既不是源图比例（4:3），
							 * 也不是显示比例（约 2.5:1），三者互不相符。
							 * 现在改为引用按显示比例预生成的 960x380 / 1920x760 两档，
							 * 不再下载会被裁掉的那部分像素。
							 *
							 * sizes：轮播容器最大 960px，窄屏时占满视口宽度。
							 */
							/*
							 * 减去 .sa-container 左右各 20px 的 padding。
							 *
							 * 原本写的是 100vw，比实际显示宽度多报 40px，浏览器因此
							 * 会挑更大的候选图。PageSpeed 的「改进图片传送」正是这一条
							 * （实际显示 556 宽，却取了更大的文件，约 20 KiB 浪费）。
							 *
							 * 补了 640w 候选。
							 *
							 * 上一版这里写着「不加 640w，因为 cover 会放大反而更糊」，
							 * 那个判断错了：它假设手机上显示框是 1.69:1，而 PageSpeed
							 * 实测报的所需显示尺寸是 556x220 —— 比例 2.53:1，与图片的
							 * 960:380 完全一致，根本不存在裁切，纯粹是把 960 宽的图
							 * 缩到 556 显示。
							 * 640x253 仍然大于所需的 556x220，不会放大。
							 * 实测体积：slide-1 的 960w 是 40.1 KB，640w 只有 24.8 KB。
							 */
							$sa_sizes = '(max-width: 1000px) calc(100vw - 40px), 960px';
							?>
							<picture>
								<source
									type="image/webp"
									srcset="<?php echo esc_url( $sa_img_base . $sa_stem . '-640w.webp' ); ?> 640w,
									        <?php echo esc_url( $sa_img_base . $sa_stem . '-960w.webp' ); ?> 960w,
									        <?php echo esc_url( $sa_img_base . $sa_stem . '-1920w.webp' ); ?> 1920w"
									sizes="<?php echo esc_attr( $sa_sizes ); ?>">
								<img src="<?php echo esc_url( $sa_img_base . $sa_stem . '-960w.jpg' ); ?>"
									srcset="<?php echo esc_url( $sa_img_base . $sa_stem . '-640w.jpg' ); ?> 640w,
									        <?php echo esc_url( $sa_img_base . $sa_stem . '-960w.jpg' ); ?> 960w,
									        <?php echo esc_url( $sa_img_base . $sa_stem . '-1920w.jpg' ); ?> 1920w"
									sizes="<?php echo esc_attr( $sa_sizes ); ?>"
									alt="<?php echo esc_attr( $slide['alt'] ); ?>"
									width="960" height="380"
									decoding="async"
									<?php
									/*
									 * 第一张不加 fetchpriority="high"。
									 *
									 * 轮播整块在 .sa-hero 之下，任何视口下都不在首屏内，
									 * 手机上更是远在折叠线以下。给它 high 优先级，等于
									 * 在受限带宽上跟真正的 LCP 元素（hero 背景图）抢线，
									 * 两张图一起变慢。
									 *
									 * 保留 eager 而不是改 lazy：桌面端视口高，轮播可能
									 * 刚好露出上边缘，lazy 会让它明显后到。默认优先级
									 * 意味着浏览器会排在 high 之后取它，正是想要的次序。
									 */
									?>
									<?php if ( $sa_is_first ) : ?>
										loading="eager"
									<?php else : ?>
										loading="lazy"
									<?php endif; ?>
								>
							</picture>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php if ( count( $sa_slides ) > 1 ) : ?>
				<button type="button" class="sa-carousel__prev" aria-label="<?php esc_attr_e( '前へ', 'sa-theme' ); ?>">‹</button>
				<button type="button" class="sa-carousel__next" aria-label="<?php esc_attr_e( '次へ', 'sa-theme' ); ?>">›</button>
				<?php
				/*
				 * 这里原本挂着 aria-hidden="true"。
				 *
				 * 容器里装的是 main.js 生成的 <button>（带 aria-label="slide N"），
				 * 是真正可聚焦的导航控件。aria-hidden 会把它们整体从无障碍树上摘掉 ——
				 * 键盘能 Tab 到，读屏软件却读不出来，是比不加还糟的状态。
				 * Lighthouse 的「[aria-hidden=true] 元素包含可聚焦的下级元素」正是这一条。
				 *
				 * 圆点是轮播的合法控件，该做的是让它可访问，而不是藏起来。
				 */
				?>
				<?php
				/*
				 * role 用 group 而不是 tablist：tablist 要求子元素都是 role="tab"，
				 * 而 main.js 生成的是普通 <button>，标成 tablist 反而会引入一条新的
				 * ARIA 违规。group 对子元素没有要求。
				 *
				 * data-dot-label 把按钮文案交给 PHP 翻译 —— main.js 里原本硬编码
				 * 'slide ' + N，日文和中文页面上也读作英文。
				 */
				?>
				<div class="sa-carousel__dots" role="group"
					aria-label="<?php esc_attr_e( 'スライド切り替え', 'sa-theme' ); ?>"
					data-dot-label="<?php esc_attr_e( 'スライド %d', 'sa-theme' ); ?>"></div>
			<?php endif; ?>
		</div>
			<?php
		endif;
		?>

		<div class="sa-logos">
			<span><?php esc_html_e( '語学学校', 'sa-theme' ); ?></span>
			<span><?php esc_html_e( '専門学校', 'sa-theme' ); ?></span>
			<span><?php esc_html_e( '大学（学部）', 'sa-theme' ); ?></span>
			<span><?php esc_html_e( '大学院', 'sa-theme' ); ?></span>
			<span><?php esc_html_e( '短期大学', 'sa-theme' ); ?></span>
		</div>
	</div>
</section>

<!-- ============ FAQ（含结构化数据） ============ -->
<section class="sa-section sa-section--soft" id="faq">
	<div class="sa-container">
		<div class="sa-section__head">
			<span class="sa-section__tag">FAQ</span>
			<h2 class="sa-section__title"><?php esc_html_e( 'よくある質問', 'sa-theme' ); ?></h2>
		</div>
		<div class="sa-faq">
			<?php foreach ( $faqs as $faq ) : ?>
				<div class="sa-faq__item">
					<p class="sa-faq__q"><?php echo esc_html( $faq['q'] ); ?></p>
					<p class="sa-faq__a"><?php echo esc_html( $faq['a'] ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
<?php
// FAQ 结构化数据（SEO）
if ( function_exists( 'sa_output_faq_schema' ) ) {
	sa_output_faq_schema( $faqs );
}
?>

<!-- ============ 结尾 CTA ============ -->
<section class="sa-cta-band">
	<div class="sa-container">
		<h2><?php esc_html_e( 'まずは無料で、あなたに合う学校を見つけよう', 'sa-theme' ); ?></h2>
		<p><?php esc_html_e( '入力は30秒。しつこい勧誘はありません。', 'sa-theme' ); ?></p>
		<a href="#lead-form" class="sa-btn sa-btn--primary sa-btn--lg" data-sa-cta="cta-band"><?php esc_html_e( '無料でAI診断を受ける', 'sa-theme' ); ?></a>
	</div>
</section>

</main>
<?php
get_footer();
