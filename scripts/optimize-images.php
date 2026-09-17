<?php
/**
 * optimize-images.php — 按真实显示尺寸重新生成主题图片，并输出 WebP。
 *
 * 为什么需要：
 *
 *   原始素材是从图库直接下载的大图，与页面实际显示尺寸严重不匹配：
 *     轮播图  源 1200x900，实际显示 960x380（object-fit: cover）
 *             —— 下载了 900px 的高度，只用到 380px，其余像素被裁掉丢弃
 *     hero    源 1920x1080，作为 CSS 背景铺满视口
 *   首页图片合计 1.2MB，是 LCP 的主要拖累。Core Web Vitals 直接参与排名，
 *   这不是「锦上添花」的优化。
 *
 *   做两件事：
 *     1. 先按显示比例裁剪，再缩放到实际需要的宽度（含 2x 视网膜屏）
 *     2. 同时输出 WebP —— 同等观感下通常比 JPEG 小 25~35%
 *
 * 幂等：输出比源文件新时跳过，可反复执行。
 *
 * 用法：
 *   php scripts/optimize-images.php            # 生成
 *   php scripts/optimize-images.php --force    # 忽略时间戳，强制重新生成
 *
 * 兼容 PHP 7.4。依赖 GD（需 WebP 支持）。
 *
 * @package StudyAbroadTheme
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "仅限命令行执行\n" );
	exit( 1 );
}

if ( ! extension_loaded( 'gd' ) ) {
	fwrite( STDERR, "缺少 GD 扩展。CentOS: yum install -y php-gd 后重启 php-fpm\n" );
	exit( 1 );
}
if ( ! function_exists( 'imagewebp' ) ) {
	fwrite( STDERR, "GD 未编译 WebP 支持，无法生成 .webp。\n" );
	fwrite( STDERR, "仍可继续（只重新压缩 JPEG），但收益会小很多。\n" );
}

$force   = in_array( '--force', array_slice( $argv, 1 ), true );
$img_dir = dirname( __DIR__ ) . '/wp-content/themes/study-abroad-theme/assets/images';

/*
 * 目标规格。
 *
 * ratio 取自 CSS 的实际显示框，不是源图比例：
 *   轮播图 .sa-carousel 最大宽 960px、img 高 380px → 960/380
 *   hero   作为背景铺满视口，保留 16:9
 *
 * widths 里的 1x 对应布局宽度，2x 供视网膜屏。再大没有意义 ——
 * 超出显示尺寸的像素只会增加传输量，不会提升观感。
 */
$targets = array(
	/*
	 * hero 用低质量编码，这不是偷工减料。
	 *
	 * 这张图上压着一层不透明度 .92 的渐变遮罩（style.css 的 .sa-hero__bg::after），
	 * 只有 8% 透出来 —— 画面里能看到的是大块色彩关系，不是细节。
	 * 按 q80 编码等于在为看不见的东西付字节，而它又是 LCP 资源。
	 *
	 * 验证方式：把 q80 与 q30 分别叠加同样的 .92 遮罩合成出来对比，
	 * 肉眼无法区分。实测体积 q80 34.7 KB / q50 21.9 KB / q30 15.7 KB。
	 * 取 q50 而不是更低，是留一档余量 —— 万一日后调低遮罩浓度，
	 * 不至于立刻露出压缩痕迹。
	 *
	 * 轮播图不用这个策略：它们没有遮罩，是直接看的照片。
	 */
	'hero-bg.jpg' => array(
		'ratio'   => 1920 / 1080,
		'widths'  => array( 400, 640, 1280, 1920 ),
		'quality' => array( 'jpg' => 68, 'webp' => 50 ),
	),
	'slide-1.jpg' => array(
		'ratio'   => 960 / 380,
		'widths'  => array( 640, 960, 1920 ),
		'quality' => array( 'jpg' => 82, 'webp' => 78 ),
	),
	'slide-2.jpg' => array( 'ratio' => 960 / 380, 'widths' => array( 640, 960, 1920 ), 'quality' => array( 'jpg' => 82, 'webp' => 78 ) ),
	'slide-3.jpg' => array( 'ratio' => 960 / 380, 'widths' => array( 640, 960, 1920 ), 'quality' => array( 'jpg' => 82, 'webp' => 78 ) ),
);

/**
 * 居中裁剪到指定宽高比，再缩放到目标宽度。
 *
 * 先裁后缩的顺序不能反：先缩放会把整幅图压扁成目标比例，人物会变形；
 * 先按比例裁掉多余部分，再等比缩放，画面才不失真。
 *
 * @param resource|GdImage $src   源图像。
 * @param float            $ratio 目标宽高比。
 * @param int              $w     目标宽度。
 * @return resource|GdImage
 */
function oi_crop_resize( $src, $ratio, $w ) {
	$sw = imagesx( $src );
	$sh = imagesy( $src );

	// 在源图上取一块符合目标比例的最大区域
	if ( ( $sw / $sh ) > $ratio ) {
		$ch = $sh;
		$cw = (int) round( $sh * $ratio );
	} else {
		$cw = $sw;
		$ch = (int) round( $sw / $ratio );
	}
	$cx = (int) round( ( $sw - $cw ) / 2 );
	$cy = (int) round( ( $sh - $ch ) / 2 );

	$h   = (int) round( $w / $ratio );
	$dst = imagecreatetruecolor( $w, $h );
	imagecopyresampled( $dst, $src, 0, 0, $cx, $cy, $w, $h, $cw, $ch );

	return $dst;
}

function oi_kb( $bytes ) {
	return sprintf( '%.0f KB', $bytes / 1024 );
}

echo "============================================================\n";
echo "  主题图片优化\n";
echo "  目录: {$img_dir}\n";
echo "============================================================\n\n";

$total_before = 0;
$total_after  = 0;
$generated    = 0;
$skipped      = 0;

foreach ( $targets as $file => $spec ) {
	$path = $img_dir . '/' . $file;
	if ( ! is_readable( $path ) ) {
		echo "  [跳过] {$file} 不存在\n";
		continue;
	}

	$base     = pathinfo( $file, PATHINFO_FILENAME );
	$src_size = filesize( $path );
	$dims     = getimagesize( $path );

	printf( "  %s  源 %dx%d  %s\n", $file, $dims[0], $dims[1], oi_kb( $src_size ) );
	$total_before += $src_size;

	$src = @imagecreatefromjpeg( $path );
	if ( ! $src ) {
		echo "    ✗ 无法读取（不是有效的 JPEG？）\n";
		continue;
	}

	foreach ( $spec['widths'] as $w ) {
		$h = (int) round( $w / $spec['ratio'] );

		foreach ( array( 'webp', 'jpg' ) as $fmt ) {
			if ( 'webp' === $fmt && ! function_exists( 'imagewebp' ) ) {
				continue;
			}
			$out = sprintf( '%s/%s-%dw.%s', $img_dir, $base, $w, $fmt );

			// 幂等：输出比源文件新则跳过
			if ( ! $force && file_exists( $out ) && filemtime( $out ) >= filemtime( $path ) ) {
				$total_after += filesize( $out );
				++$skipped;
				printf( "    · %-28s %8s  (已存在，跳过)\n", basename( $out ), oi_kb( filesize( $out ) ) );
				continue;
			}

			$dst = oi_crop_resize( $src, $spec['ratio'], $w );
			if ( 'webp' === $fmt ) {
				imagewebp( $dst, $out, $spec['quality']['webp'] );
			} else {
				imagejpeg( $dst, $out, $spec['quality']['jpg'] );
			}
			imagedestroy( $dst );

			$sz            = filesize( $out );
			$total_after  += $sz;
			++$generated;
			printf( "    ✓ %-28s %8s  %dx%d\n", basename( $out ), oi_kb( $sz ), $w, $h );
		}
	}

	imagedestroy( $src );
	echo "\n";
}

echo "------------------------------------------------------------\n";
printf( "  新生成 %d 个，跳过 %d 个\n", $generated, $skipped );
printf( "  源图合计 %s → 产出合计 %s\n", oi_kb( $total_before ), oi_kb( $total_after ) );
echo "\n";
echo "  注意：产出总量大于源图是正常的 —— 每张图有多个尺寸与格式。\n";
echo "  真正影响加载的是「单次访问实际下载的那一个」，浏览器会按\n";
echo "  srcset/sizes 与 WebP 支持情况只取一个。\n";
echo "------------------------------------------------------------\n";
echo "\n";
echo "源图（hero-bg.jpg / slide-*.jpg）保留作为再生成的基准，\n";
echo "但页面不再直接引用它们。\n";
