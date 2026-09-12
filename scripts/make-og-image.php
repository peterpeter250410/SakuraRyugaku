<?php
/**
 * make-og-image.php — 生成社交分享图（Open Graph / Twitter Card）。
 *
 * 为什么要按语种各生成一张：
 *   分享图是用户在社交平台看到的第一眼内容，直接影响点击率。
 *   一张日文图发给中国学生、或一张中文图发到日本社群，都会降低点击意愿。
 *   主题的 sa_share_image() 会按当前语种优先选取对应语言的图。
 *
 * 尺寸 1200x630 是 Open Graph 的推荐比例（1.91:1），
 * Facebook / X / LINE / 微信 均按此裁切。
 *
 * 用法：
 *   php scripts/make-og-image.php              # 生成全部语种
 *   php scripts/make-og-image.php zh_CN        # 只生成指定语种
 *
 * 依赖：PHP GD（需 FreeType 与 JPEG 支持）+ 一款能覆盖对应语种字形的字体。
 * 字体按候选列表自动探测；缺少 CJK 字体时会明确报错而不是输出乱码方块。
 *
 * 兼容 PHP 7.4。
 *
 * @package StudyAbroadTheme
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "仅限命令行执行\n" );
	exit( 1 );
}

if ( ! extension_loaded( 'gd' ) ) {
	fwrite( STDERR, "缺少 PHP GD 扩展。CentOS: yum install -y php-gd 后重启 php-fpm\n" );
	exit( 1 );
}

$gd = gd_info();
if ( empty( $gd['FreeType Support'] ) ) {
	fwrite( STDERR, "GD 缺少 FreeType 支持，无法渲染文字。\n" );
	exit( 1 );
}
if ( empty( $gd['JPEG Support'] ) ) {
	fwrite( STDERR, "GD 缺少 JPEG 支持。\n" );
	exit( 1 );
}

$theme_dir = dirname( __DIR__ ) . '/wp-content/themes/study-abroad-theme';
$out_dir   = $theme_dir . '/assets/images';

if ( ! is_dir( $out_dir ) && ! mkdir( $out_dir, 0755, true ) ) {
	fwrite( STDERR, "无法创建目录: {$out_dir}\n" );
	exit( 1 );
}

/* -------------------------------------------------------------------------
 * 字体探测
 *
 * 按语种给出候选路径，取第一个存在的。
 * 中日文字形集不同（简体「费」「络」等在日文字体中可能缺字或字形有差异），
 * 因此两者各自优先使用本语种字体。
 * ---------------------------------------------------------------------- */
$font_candidates = array(
	'ja'    => array(
		'/usr/share/fonts/truetype/noto/NotoSansCJKjp-Bold.otf',
		'/usr/share/fonts/opentype/noto/NotoSansCJKjp-Bold.otf',
		'/usr/share/fonts/opentype/ipafont-gothic/ipag.ttf',
		'/etc/alternatives/fonts-japanese-gothic.ttf',
		'/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
	),
	'zh_CN' => array(
		'/usr/share/fonts/truetype/noto/NotoSansCJKsc-Bold.otf',
		'/usr/share/fonts/opentype/noto/NotoSansCJKsc-Bold.otf',
		'/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
		'/usr/share/fonts/wenquanyi/wqy-zenhei/wqy-zenhei.ttc',
		'/usr/share/fonts/opentype/ipafont-gothic/ipag.ttf',
	),
	'en_US' => array(
		'/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
		'/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
		'/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
	),
);

/**
 * 取第一个存在的字体路径。
 *
 * @param array<int,string> $paths 候选路径。
 * @return string 空字符串表示都不存在。
 */
function og_pick_font( array $paths ) {
	foreach ( $paths as $p ) {
		if ( is_readable( $p ) ) {
			return $p;
		}
	}
	return '';
}

/* -------------------------------------------------------------------------
 * 各语种文案
 *
 * 与 inc/i18n.php 的 site_name 保持一致；改品牌名时两处都要改。
 * ---------------------------------------------------------------------- */
$locales = array(
	'ja'    => array(
		'file'    => 'og-default.jpg', // 默认语种用无后缀文件名，作为总兜底
		'name'    => '日本留学サポート',
		'tagline' => '無料・最短即日で学校マッチング',
		'name_size'    => 62,
		'tagline_size' => 30,
	),
	'zh_CN' => array(
		'file'    => 'og-default-zh_CN.jpg',
		'name'    => '日本留学官网',
		'tagline' => '免费院校匹配 · 最快当天出结果',
		'name_size'    => 64,
		'tagline_size' => 30,
	),
	'en_US' => array(
		'file'    => 'og-default-en_US.jpg',
		'name'    => 'Study in Japan',
		'tagline' => 'Free school matching, results as soon as today',
		'name_size'    => 62,
		'tagline_size' => 26,
	),
);

$only = isset( $argv[1] ) ? $argv[1] : '';
if ( '' !== $only && ! isset( $locales[ $only ] ) ) {
	fwrite( STDERR, "未知语种: {$only}（可选: " . implode( ', ', array_keys( $locales ) ) . "）\n" );
	exit( 1 );
}

const OG_W = 1200;
const OG_H = 630;

/**
 * 生成一张分享图。
 *
 * @param string $font    字体路径。
 * @param array  $conf    语种文案配置。
 * @param string $outfile 输出路径。
 * @return bool
 */
function og_render( $font, array $conf, $outfile ) {
	$im = imagecreatetruecolor( OG_W, OG_H );
	if ( ! $im ) {
		return false;
	}
	imageantialias( $im, true );

	// --- 配色：与 style.css 的设计变量一致 ---
	$c_bg      = imagecolorallocate( $im, 253, 249, 248 ); // #fdf9f8 和风暖白底
	$c_ink     = imagecolorallocate( $im, 26, 26, 46 );    // #1a1a2e 标题
	$c_muted   = imagecolorallocate( $im, 107, 114, 128 ); // #6b7280 副标题
	$c_primary = imagecolorallocate( $im, 212, 55, 44 );   // #d4372c 品牌朱红

	imagefilledrectangle( $im, 0, 0, OG_W, OG_H, $c_bg );

	// --- 右侧大圆：日之丸 / 樱花意象，渗出画布右缘 ---
	// GD 没有径向渐变，用多层半透明同心圆叠加模拟。
	// 步长过大或 alpha 线性递增都会产生可见的同心环带，
	// 因此步长取 2px，并用二次曲线分布 alpha，使过渡集中在外缘。
	//
	// 圆心刻意放在画布右外侧：圆在文字行高度上的左边缘必须落在安全区右侧，
	// 否则文字会压到红底上，深色字叠红底几乎不可读。
	$cx    = OG_W + 40;
	$cy    = (int) ( OG_H / 2 );
	$r_max = 400;
	for ( $r = $r_max; $r > 0; $r -= 2 ) {
		$t     = $r / $r_max;            // 1 = 最外圈，0 = 圆心
		$alpha = (int) round( 127 * ( 1 - $t * $t ) * 0.92 );
		if ( $alpha < 1 ) {
			continue;
		}
		$col = imagecolorallocatealpha( $im, 212, 55, 44, 127 - $alpha );
		imagefilledellipse( $im, $cx, $cy, $r * 2, $r * 2, $col );
	}

	// --- 顶部品牌色条 ---
	imagefilledrectangle( $im, 0, 0, OG_W, 10, $c_primary );

	// --- Logo 圆点 ---
	imagefilledellipse( $im, 92, 150, 26, 26, $c_primary );

	$left = 124;

	/*
	 * 安全区宽度：取圆在各文字行高度上的最左边缘，再留 40px 余量。
	 * 圆方程 x = cx - sqrt(r^2 - (y-cy)^2)，y 取最靠近圆心的那一行
	 * （此处为副标题行，离 cy 最近，因此圆在该行侵入最深）。
	 */
	$probe_y  = 300;
	$dy       = abs( $probe_y - $cy );
	$circle_x = $cx - (int) sqrt( max( 0, $r_max * $r_max - $dy * $dy ) );
	$safe_w   = max( 360, $circle_x - $left - 40 );

	// --- 站点名称（超出安全区时自动缩小字号，避免压到红底）---
	$name      = $conf['name'];
	$name_size = $conf['name_size'];
	while ( $name_size > 28 ) {
		$box = imagettfbbox( $name_size, 0, $font, $name );
		if ( abs( $box[2] - $box[0] ) <= $safe_w ) {
			break;
		}
		--$name_size;
	}
	imagettftext( $im, $name_size, 0, $left, 172, $c_ink, $font, $name );

	// --- 分隔线 ---
	imagefilledrectangle( $im, $left, 218, $left + 90, 222, $c_primary );

	// --- 副标题（按安全区宽度换行）---
	$lines = og_wrap( $conf['tagline'], $font, $conf['tagline_size'], $safe_w );
	$y     = 290;
	foreach ( $lines as $line ) {
		imagettftext( $im, $conf['tagline_size'], 0, $left, $y, $c_muted, $font, $line );
		$y += (int) round( $conf['tagline_size'] * 1.6 );
	}

	// --- 底部域名 ---
	imagettftext( $im, 26, 0, $left, OG_H - 70, $c_primary, $font, 'studyinjp.com' );

	$ok = imagejpeg( $im, $outfile, 88 );
	imagedestroy( $im );
	return $ok;
}

/**
 * 按像素宽度折行。
 *
 * CJK 没有空格分词，因此逐字符累加测宽；含空格的拉丁文按词折。
 *
 * @param string $text  文本。
 * @param string $font  字体路径。
 * @param int    $size  字号。
 * @param int    $max_w 最大宽度（像素）。
 * @return array<int,string>
 */
function og_wrap( $text, $font, $size, $max_w ) {
	$measure = function ( $s ) use ( $font, $size ) {
		$box = imagettfbbox( $size, 0, $font, $s );
		return abs( $box[2] - $box[0] );
	};

	if ( $measure( $text ) <= $max_w ) {
		return array( $text );
	}

	$lines = array();

	if ( false !== strpos( $text, ' ' ) ) {
		// 拉丁文：按词折行
		$words = explode( ' ', $text );
		$cur   = '';
		foreach ( $words as $w ) {
			$try = '' === $cur ? $w : $cur . ' ' . $w;
			if ( $measure( $try ) > $max_w && '' !== $cur ) {
				$lines[] = $cur;
				$cur     = $w;
			} else {
				$cur = $try;
			}
		}
		if ( '' !== $cur ) {
			$lines[] = $cur;
		}
		return $lines;
	}

	// CJK：逐字符折行
	$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
	$cur   = '';
	foreach ( $chars as $ch ) {
		$try = $cur . $ch;
		if ( $measure( $try ) > $max_w && '' !== $cur ) {
			$lines[] = $cur;
			$cur     = $ch;
		} else {
			$cur = $try;
		}
	}
	if ( '' !== $cur ) {
		$lines[] = $cur;
	}
	return $lines;
}

/* ---------------------------------------------------------------- 主流程 */

$made   = 0;
$failed = 0;

foreach ( $locales as $key => $conf ) {
	if ( '' !== $only && $key !== $only ) {
		continue;
	}

	$font = og_pick_font( $font_candidates[ $key ] );
	if ( '' === $font ) {
		fwrite( STDERR, "[跳过] {$key}: 找不到可用字体。候选路径:\n" );
		foreach ( $font_candidates[ $key ] as $p ) {
			fwrite( STDERR, "          {$p}\n" );
		}
		if ( 'en_US' !== $key ) {
			fwrite( STDERR, "        安装 CJK 字体后重试:\n" );
			fwrite( STDERR, "          CentOS: yum install -y google-noto-sans-cjk-ttc-fonts\n" );
			fwrite( STDERR, "          Debian: apt install -y fonts-noto-cjk\n" );
		}
		++$failed;
		continue;
	}

	$outfile = $out_dir . '/' . $conf['file'];
	if ( og_render( $font, $conf, $outfile ) ) {
		printf(
			"  [OK] %-6s → %s (%s KB)  字体: %s\n",
			$key,
			$conf['file'],
			number_format( filesize( $outfile ) / 1024, 1 ),
			basename( $font )
		);
		++$made;
	} else {
		fwrite( STDERR, "  [失败] {$key}: 图像写入失败，检查目录权限: {$out_dir}\n" );
		++$failed;
	}
}

echo "\n";
printf( "生成 %d 张，失败/跳过 %d 张\n", $made, $failed );
if ( $made > 0 ) {
	echo "输出目录: " . $out_dir . "\n";
	echo "提示: 这是可用的占位图。有设计资源后直接替换同名文件即可，无需改代码。\n";
}

exit( $failed > 0 && 0 === $made ? 1 : 0 );
