<?php
/**
 * 生成站点标识（桜のマーク）。
 *
 * 用法：
 *   php scripts/make-logo.php
 *
 * 产出（均写入主题的 assets/images/）：
 *   logo-mark.svg        花形、透明底、fill=currentColor —— 页眉页脚内联，
 *                        替换原来的「●」占位符，颜色由 CSS 决定
 *   site-icon-512.png    朱红圆角方块 + 白色花形 —— 上传到「设置→常规→站点图标」
 *   site-icon-192.png    同上，PWA / 安卓主屏图标常用尺寸
 *
 * 为什么标识里不放文字：
 *
 *   站点名按语种有三个（日本留学サポート / 日本留学官网 / Study in Japan，
 *   见 inc/i18n.php）。带字的标识就得做三个版本，而 Organization 结构化数据
 *   里只能有一个 logo —— 三个 logo 对应一个机构是自相矛盾的。
 *   因此只做纯图形标识，文字交给页眉里的 <span> 按语种渲染。
 *
 * 为什么 SVG 与 PNG 由同一份参数生成：
 *
 *   手工分别画两份，改了一处忘了另一处，页眉和浏览器标签页就会是两个标识。
 *   这里几何形状只定义一次（PETAL 常量），SVG 直接写贝塞尔曲线，
 *   PNG 把同样的曲线离散成多边形再交给 GD 填充。
 *
 * 关于锯齿：
 *
 *   GD 的 imagefilledpolygon 没有抗锯齿。所以先按 4 倍尺寸绘制，
 *   再用 imagecopyresampled 缩到目标尺寸 —— 缩小时的重采样就是抗锯齿。
 *   直接按 512 画出来的边缘会有明显的台阶。
 *
 * @package StudyAbroadTheme
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

if ( ! extension_loaded( 'gd' ) ) {
	fwrite( STDERR, "需要 GD 扩展。\n" );
	exit( 1 );
}

$out_dir = dirname( __DIR__ ) . '/wp-content/themes/study-abroad-theme/assets/images';
if ( ! is_dir( $out_dir ) ) {
	fwrite( STDERR, "找不到目录: {$out_dir}\n" );
	exit( 1 );
}

/* -------------------------------------------------------------------------
 * 几何定义
 * ---------------------------------------------------------------------- */

/*
 * 一片花瓣，定义在「以花心为原点、向上为外」的局部坐标里。
 * x = 横向偏移，y = 离花心的距离，单位为外接半径（1.0 = 花瓣尖端）。
 *
 * 形状要点：
 *   - 腰部最宽处放在 0.6～0.7，比放在中点更接近真实樱花（上宽下窄）。
 *   - 尖端有一个凹口（切れ込み）。这是樱花区别于梅花的唯一特征，
 *     去掉它就只是一朵「五瓣花」了。凹口不能太深：512px 下看着正好的深度，
 *     缩到 16px 的 favicon 就会糊成一团，所以取 0.10 这个偏浅的值。
 *   - 花瓣根部不收到原点，留 0.20 的距离，再用一个中心圆盘把五瓣焊在一起。
 *     直接收到原点的话，五条曲线会在中心挤出细碎的交叠，缩图后发灰。
 */
const PETAL = array(
	// 花瓣长度（r_tip - r_base）与最大半宽的比值决定「胖瘦」。
	// 0.87 : 0.36 ≈ 2.4 时花瓣细长，五瓣看着像放射状的刀片而不是一朵花；
	// 这里压到 0.70 : 0.40 ≈ 1.75，接近家纹里樱花的比例。
	'base_half'  => 0.130, // 根部半宽
	'r_base'     => 0.28,  // 根部离花心的距离
	'r_tip'      => 1.00,  // 尖端离花心的距离
	'tip_half'   => 0.285, // 尖端凹口两侧的半宽
	'notch'      => 0.155, // 凹口深度
	// c1 收窄 → 腰部细；c2 的 x 大于 tip_half → 曲线在接近尖端处外鼓，
	// 形成圆肩。二者合起来就是樱花「细腰、宽圆头」的轮廓。
	// c2 收窄的话花瓣会一路尖到头，看着像枫叶而不是樱花。
	'c1'         => array( 0.372, 0.470 ), // 下段控制点（x, y）
	'c2'         => array( 0.478, 0.905 ), // 上段控制点（x, y）
	/*
	 * 凹口的两个控制点，相对量：x 是 tip_half 的倍数，y 是相对 r_tip 的偏移
	 * （notch_c2 的 y 再乘凹口深度）。
	 *
	 * notch_c1 要落在 r_tip 之上（y 为正），这样曲线离开尖端外角时，
	 * 方向与外侧曲线抵达时一致 —— 外角因此是圆的。
	 * 若让它一离开外角就往下走，两条曲线在外角处切线突变，会出现一个尖角，
	 * 整朵花看着像枫叶。
	 */
	'notch_c1'   => array( 0.70, 0.045 ),
	'notch_c2'   => array( 0.28, -0.72 ),
	'core_r'     => 0.330, // 中心圆盘半径，用于焊合五瓣
	'petals'     => 5,
	'rotate_deg' => -90.0, // 让第一瓣朝正上方
);

/**
 * 三次贝塞尔采样。
 *
 * @param array $p0 起点 [x,y]。
 * @param array $p1 控制点 1。
 * @param array $p2 控制点 2。
 * @param array $p3 终点。
 * @param int   $n  分段数。
 * @return array<int,array{0:float,1:float}>
 */
function bezier( $p0, $p1, $p2, $p3, $n ) {
	$pts = array();
	for ( $i = 1; $i <= $n; $i++ ) {
		$t = $i / $n;
		$u = 1 - $t;
		$a = $u * $u * $u;
		$b = 3 * $u * $u * $t;
		$c = 3 * $u * $t * $t;
		$d = $t * $t * $t;
		$pts[] = array(
			$a * $p0[0] + $b * $p1[0] + $c * $p2[0] + $d * $p3[0],
			$a * $p0[1] + $b * $p1[1] + $c * $p2[1] + $d * $p3[1],
		);
	}
	return $pts;
}

/**
 * 凹口两个控制点的绝对坐标（右半侧）。
 *
 * PETAL 里存的是相对量，这里统一换算 —— SVG 与 PNG 两条产线都调用它，
 * 免得各自算一遍再算错一个。
 *
 * @return array{0:array{0:float,1:float},1:array{0:float,1:float}}
 */
function notch_controls() {
	$th = PETAL['tip_half'];
	$rt = PETAL['r_tip'];
	$nd = PETAL['notch'];
	$a  = PETAL['notch_c1'];
	$b  = PETAL['notch_c2'];

	return array(
		array( $th * $a[0], $rt + $a[1] ),
		array( $th * $b[0], $rt + $b[1] * $nd ),
	);
}

/**
 * 单片花瓣的轮廓点（局部坐标，逆时针）。
 *
 * @param int $seg 每段曲线的分段数。
 * @return array<int,array{0:float,1:float}>
 */
function petal_outline( $seg = 48 ) {
	$bh = PETAL['base_half'];
	$rb = PETAL['r_base'];
	$rt = PETAL['r_tip'];
	$th = PETAL['tip_half'];
	$nd = PETAL['notch'];
	$c1 = PETAL['c1'];
	$c2 = PETAL['c2'];

	$pts = array( array( -$bh, $rb ) );

	// 左侧：根部 → 尖端左角
	$pts = array_merge(
		$pts,
		bezier(
			array( -$bh, $rb ),
			array( -$c1[0], $c1[1] ),
			array( -$c2[0], $c2[1] ),
			array( -$th, $rt ),
			$seg
		)
	);

	// 尖端凹口：左角 → 谷底 → 右角。两半互为镜像。
	list( $n1, $n2 ) = notch_controls();
	$pts             = array_merge(
		$pts,
		bezier(
			array( -$th, $rt ),
			array( -$n1[0], $n1[1] ),
			array( -$n2[0], $n2[1] ),
			array( 0.0, $rt - $nd ),
			(int) ceil( $seg / 2 )
		)
	);
	$pts = array_merge(
		$pts,
		bezier(
			array( 0.0, $rt - $nd ),
			array( $n2[0], $n2[1] ),
			array( $n1[0], $n1[1] ),
			array( $th, $rt ),
			(int) ceil( $seg / 2 )
		)
	);

	// 右侧：尖端右角 → 根部（左侧的镜像，控制点顺序反过来）
	$pts = array_merge(
		$pts,
		bezier(
			array( $th, $rt ),
			array( $c2[0], $c2[1] ),
			array( $c1[0], $c1[1] ),
			array( $bh, $rb ),
			$seg
		)
	);

	return $pts;
}

/* -------------------------------------------------------------------------
 * SVG
 * ---------------------------------------------------------------------- */

/**
 * 花瓣的 SVG path 数据（局部坐标，未旋转）。
 *
 * @return string
 */
function petal_path_d() {
	$bh = PETAL['base_half'];
	$rb = PETAL['r_base'];
	$rt = PETAL['r_tip'];
	$th = PETAL['tip_half'];
	$nd = PETAL['notch'];
	$c1 = PETAL['c1'];
	$c2 = PETAL['c2'];

	$f = function ( $v ) {
		return rtrim( rtrim( number_format( $v, 4, '.', '' ), '0' ), '.' );
	};

	list( $n1, $n2 ) = notch_controls();

	return sprintf(
		'M %s %s C %s %s %s %s %s %s C %s %s %s %s %s %s C %s %s %s %s %s %s C %s %s %s %s %s %s Z',
		$f( -$bh ),
		$f( $rb ),
		// 左侧：根部 → 尖端左角
		$f( -$c1[0] ),
		$f( $c1[1] ),
		$f( -$c2[0] ),
		$f( $c2[1] ),
		$f( -$th ),
		$f( $rt ),
		// 凹口左半：左角 → 谷底
		$f( -$n1[0] ),
		$f( $n1[1] ),
		$f( -$n2[0] ),
		$f( $n2[1] ),
		$f( 0 ),
		$f( $rt - $nd ),
		// 凹口右半：谷底 → 右角
		$f( $n2[0] ),
		$f( $n2[1] ),
		$f( $n1[0] ),
		$f( $n1[1] ),
		$f( $th ),
		$f( $rt ),
		// 右侧：尖端右角 → 根部
		$f( $c2[0] ),
		$f( $c2[1] ),
		$f( $c1[0] ),
		$f( $c1[1] ),
		$f( $bh ),
		$f( $rb )
	);
}

/**
 * 生成花形的 SVG。
 *
 * @param string $color 填充色。
 * @return string
 */
function build_svg( $color ) {
	$d    = petal_path_d();
	$n    = PETAL['petals'];
	$step = 360.0 / $n;
	$rot0 = PETAL['rotate_deg'];
	$core = rtrim( rtrim( number_format( PETAL['core_r'], 4, '.', '' ), '0' ), '.' );

	/*
	 * 五片花瓣各写一条完整 path，不用 <defs> + <use href="#p">。
	 *
	 * 这个 SVG 会被内联进页面，而页眉、抽屉、页脚三处都要用 ——
	 * 带 id 的版本内联三次，文档里就有三个 id="p"，是无效 HTML；
	 * <use> 还会全部指向第一个，形状虽然照样对，但问题被藏起来了。
	 * 展开后大约多 300 字节，换掉一整类隐患，划算。
	 */
	$paths = '';
	for ( $i = 0; $i < $n; $i++ ) {
		// 局部坐标里 y 是「向外」，SVG 里 y 向下，所以先翻转再旋转。
		$deg    = $rot0 + $i * $step + 90.0;
		$paths .= sprintf(
			"\t\t<path d=\"%s\" transform=\"rotate(%s) scale(1,-1)\"/>\n",
			$d,
			rtrim( rtrim( number_format( $deg, 4, '.', '' ), '0' ), '.' )
		);
	}

	return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="-1.1 -1.1 2.2 2.2" aria-hidden="true" focusable="false">
	<g fill="{$color}">
		<circle cx="0" cy="0" r="{$core}"/>
{$paths}	</g>
</svg>

SVG;
}

/* -------------------------------------------------------------------------
 * PNG
 * ---------------------------------------------------------------------- */

/**
 * 十六进制颜色转 RGB。
 *
 * @param string $hex 形如 #d4372c。
 * @return array{0:int,1:int,2:int}
 */
function hex_rgb( $hex ) {
	$hex = ltrim( $hex, '#' );
	return array(
		hexdec( substr( $hex, 0, 2 ) ),
		hexdec( substr( $hex, 2, 2 ) ),
		hexdec( substr( $hex, 4, 2 ) ),
	);
}

/**
 * 绘制「圆角方块底 + 花形」的图标。
 *
 * 为什么图标要带底色，而不是透明底的红花：
 *   favicon 会被放到各种背景上 —— 浏览器标签页、书签栏、深色模式、
 *   安卓主屏。透明底的朱红花放在深色或红色背景上会直接消失。
 *   给它一块自带的底色，在哪儿都认得出来。
 *
 * @param int    $size     输出边长。
 * @param string $bg_hex   底色。
 * @param string $fg_hex   花色。
 * @param float  $scale    花形占边长的比例。
 * @param float  $radius_r 圆角半径占边长的比例。
 * @return resource|GdImage
 */
function render_icon( $size, $bg_hex, $fg_hex, $scale = 0.68, $radius_r = 0.225 ) {
	$ss = 4; // 超采样倍数
	$w  = $size * $ss;

	$im = imagecreatetruecolor( $w, $w );
	imagealphablending( $im, false );
	imagesavealpha( $im, true );
	imagefill( $im, 0, 0, imagecolorallocatealpha( $im, 0, 0, 0, 127 ) );
	imagealphablending( $im, true );

	list( $br, $bg, $bb ) = hex_rgb( $bg_hex );
	list( $fr, $fg, $fb ) = hex_rgb( $fg_hex );
	$c_bg = imagecolorallocate( $im, $br, $bg, $bb );
	$c_fg = imagecolorallocate( $im, $fr, $fg, $fb );

	// 圆角方块：中间两个矩形 + 四角的圆
	$rad = (int) round( $w * $radius_r );
	imagefilledrectangle( $im, $rad, 0, $w - 1 - $rad, $w - 1, $c_bg );
	imagefilledrectangle( $im, 0, $rad, $w - 1, $w - 1 - $rad, $c_bg );
	foreach ( array( array( $rad, $rad ), array( $w - 1 - $rad, $rad ), array( $rad, $w - 1 - $rad ), array( $w - 1 - $rad, $w - 1 - $rad ) ) as $c ) {
		imagefilledellipse( $im, $c[0], $c[1], $rad * 2, $rad * 2, $c_bg );
	}

	draw_blossom( $im, $w / 2, $w / 2, $w * $scale / 2, $c_fg );

	$out = imagecreatetruecolor( $size, $size );
	imagealphablending( $out, false );
	imagesavealpha( $out, true );
	imagefill( $out, 0, 0, imagecolorallocatealpha( $out, 0, 0, 0, 127 ) );
	imagecopyresampled( $out, $im, 0, 0, 0, 0, $size, $size, $w, $w );
	imagedestroy( $im );

	return $out;
}

/**
 * 在画布上画一朵花。
 *
 * @param resource|GdImage $im    画布。
 * @param float            $cx    花心 x。
 * @param float            $cy    花心 y。
 * @param float            $r     外接半径（到花瓣尖端）。
 * @param int              $color 颜色。
 * @return void
 */
function draw_blossom( $im, $cx, $cy, $r, $color ) {
	$outline = petal_outline();
	$n       = PETAL['petals'];
	$step    = 2 * M_PI / $n;
	$rot0    = deg2rad( PETAL['rotate_deg'] );

	// 中心圆盘：把五瓣焊成一体，避免根部交叠处出现缝隙
	imagefilledellipse( $im, (int) round( $cx ), (int) round( $cy ), (int) round( PETAL['core_r'] * 2 * $r ), (int) round( PETAL['core_r'] * 2 * $r ), $color );

	for ( $i = 0; $i < $n; $i++ ) {
		$a   = $rot0 + $i * $step;
		$cos = cos( $a );
		$sin = sin( $a );
		$poly = array();
		foreach ( $outline as $p ) {
			// 局部 (x, y_out) → 画布。y_out 沿角度 a 的方向，x 沿其法线方向。
			$px     = $cx + ( $p[1] * $cos - $p[0] * $sin ) * $r;
			$py     = $cy + ( $p[1] * $sin + $p[0] * $cos ) * $r;
			$poly[] = (int) round( $px );
			$poly[] = (int) round( $py );
		}
		// PHP 8 起 imagefilledpolygon 的点数参数被废弃，两种签名都要兼容。
		if ( PHP_VERSION_ID >= 80000 ) {
			imagefilledpolygon( $im, $poly, $color );
		} else {
			imagefilledpolygon( $im, $poly, count( $poly ) / 2, $color );
		}
	}
}

/* -------------------------------------------------------------------------
 * 自检：SVG 与 PNG 必须是同一个形状
 * ---------------------------------------------------------------------- */

/**
 * 把生成的 SVG 重新采样，与 GD 画的多边形逐点比对。
 *
 * 这一项是肉眼查不出来的：PNG 可以打开看，SVG 得靠浏览器渲染，而两条产线
 * 各自把 PETAL 常量翻译成曲线。哪天改了 petal_outline() 忘了改 petal_path_d()，
 * 页眉里的标识和浏览器标签页里的图标就会是两个不一样的形状，而且
 * 谁也不会注意到。所以每次生成都验一遍。
 *
 * 注意参数是「生成出来的 SVG 文本」而不是重新调用 petal_path_d()。
 * 起初这里是自己再算一遍摆放角度，结果把 build_svg() 里的 rotate 角度
 * 改成反方向，校验照样通过 —— 因为它验的是两条公式互相一致，
 * 而不是「实际写进文件的东西」是否正确。必须解析产物本身。
 *
 * @param string $svg build_svg() 的返回值。
 * @return float 最大偏差（单位为外接半径）。
 */
function verify_svg_matches_png( $svg ) {
	// 从产物里取出花瓣 path 与它的 transform
	if ( ! preg_match_all( '/<path d="([^"]+)" transform="rotate\(([-\d.]+)\) scale\(1,-1\)"\/>/', $svg, $mm, PREG_SET_ORDER ) ) {
		fwrite( STDERR, "无法从 SVG 中解析出花瓣\n" );
		exit( 1 );
	}
	if ( count( $mm ) !== PETAL['petals'] ) {
		fwrite( STDERR, sprintf( "SVG 里有 %d 片花瓣，应为 %d\n", count( $mm ), PETAL['petals'] ) );
		exit( 1 );
	}

	$d      = $mm[0][1];
	$angles = array();
	foreach ( $mm as $one ) {
		if ( $one[1] !== $d ) {
			fwrite( STDERR, "五片花瓣的 path 不一致\n" );
			exit( 1 );
		}
		$angles[] = (float) $one[2];
	}

	// 解析 "M x y C ... C ... C ... C ... Z"
	if ( ! preg_match_all( '/-?\d+(?:\.\d+)?/', $d, $m ) ) {
		fwrite( STDERR, "无法解析 path\n" );
		exit( 1 );
	}
	$nums = array_map( 'floatval', $m[0] );
	if ( count( $nums ) !== 2 + 4 * 6 ) {
		fwrite( STDERR, sprintf( "path 数字个数异常：%d（应为 %d）\n", count( $nums ), 2 + 4 * 6 ) );
		exit( 1 );
	}

	$cur = array( $nums[0], $nums[1] );
	$i   = 2;
	$seg = 48;
	$re  = array( $cur );
	foreach ( array( $seg, (int) ceil( $seg / 2 ), (int) ceil( $seg / 2 ), $seg ) as $n ) {
		$p1  = array( $nums[ $i ], $nums[ $i + 1 ] );
		$p2  = array( $nums[ $i + 2 ], $nums[ $i + 3 ] );
		$p3  = array( $nums[ $i + 4 ], $nums[ $i + 5 ] );
		$re  = array_merge( $re, bezier( $cur, $p1, $p2, $p3, $n ) );
		$cur = $p3;
		$i  += 6;
	}

	$poly = petal_outline( $seg );
	if ( count( $re ) !== count( $poly ) ) {
		fwrite( STDERR, sprintf( "点数不一致：SVG %d / GD %d\n", count( $re ), count( $poly ) ) );
		exit( 1 );
	}

	$max = 0.0;
	foreach ( $poly as $k => $p ) {
		$max = max( $max, hypot( $p[0] - $re[ $k ][0], $p[1] - $re[ $k ][1] ) );
	}

	// path 里的坐标被格式化成 4 位小数，所以允许一点舍入误差。
	if ( $max > 1e-3 ) {
		fwrite( STDERR, sprintf( "SVG 与 PNG 的花瓣轮廓不一致，最大偏差 %.5f\n", $max ) );
		exit( 1 );
	}

	/*
	 * 轮廓一致还不够，摆放方式也得一致。
	 *
	 * SVG 用 transform="rotate(a+90) scale(1,-1)"，GD 里是手写的旋转矩阵，
	 * 两边对「y 向外」与「y 向下」的处理各来一次符号翻转。这种地方错一个负号，
	 * 结果是整朵花镜像或者转了 90 度 —— PNG 打开就能看出来，SVG 不在浏览器里
	 * 打开是看不出来的。所以这里把两套变换都算一遍做比对。
	 */
	$n    = PETAL['petals'];
	$step = 360.0 / $n;
	$rot0 = PETAL['rotate_deg'];

	for ( $i = 0; $i < $n; $i++ ) {
		// GD 用的角度由常量算；SVG 用的角度取自产物里解析出来的 rotate()。
		$a   = deg2rad( $rot0 + $i * $step );
		$th  = deg2rad( $angles[ $i ] );
		$cos = cos( $a );
		$sin = sin( $a );

		foreach ( $poly as $p ) {
			// GD：局部 (x 横向, y 向外) → 画布偏移
			$gx = $p[1] * $cos - $p[0] * $sin;
			$gy = $p[1] * $sin + $p[0] * $cos;

			// SVG：先 scale(1,-1) 再 rotate(th)，y 轴向下
			$sx0 = $p[0];
			$sy0 = -$p[1];
			$sx  = $sx0 * cos( $th ) - $sy0 * sin( $th );
			$sy  = $sx0 * sin( $th ) + $sy0 * cos( $th );

			if ( hypot( $gx - $sx, $gy - $sy ) > 1e-9 ) {
				fwrite(
					STDERR,
					sprintf(
						"第 %d 片花瓣的摆放不一致：GD (%.6f, %.6f) / SVG (%.6f, %.6f)\n",
						$i,
						$gx,
						$gy,
						$sx,
						$sy
					)
				);
				exit( 1 );
			}
		}
	}

	return $max;
}

$logo_svg = build_svg( 'currentColor' );
$dev      = verify_svg_matches_png( $logo_svg );

/* -------------------------------------------------------------------------
 * 写出
 * ---------------------------------------------------------------------- */

$primary = '#d4372c'; // 与 style.css 的 --sa-primary 一致
$white   = '#ffffff';

$files = array();

/*
 * 花形用 currentColor 而不是写死颜色：
 * 页眉里是朱红，深色页脚里要白色。用 currentColor 的话一个文件就够了，
 * 颜色交给 CSS 的 .sa-logo__mark 控制 —— 否则得维护两个只差一个色值的文件，
 * 改形状时还得记得两个都重新生成。
 */
file_put_contents( "{$out_dir}/logo-mark.svg", $logo_svg );
$files[] = 'logo-mark.svg';

// 上一版生成过一个写死白色的变体，改用 currentColor 后它就多余了。
if ( file_exists( "{$out_dir}/logo-mark-white.svg" ) ) {
	unlink( "{$out_dir}/logo-mark-white.svg" );
}

foreach ( array( 512, 192 ) as $size ) {
	$im = render_icon( $size, $primary, $white );
	imagepng( $im, "{$out_dir}/site-icon-{$size}.png", 9 );
	imagedestroy( $im );
	$files[] = "site-icon-{$size}.png";
}

// 预览图：并排看不同尺寸缩下来是什么样，确认小尺寸仍然认得出是樱花。
$preview_sizes = array( 512, 128, 64, 32, 16 );
$pad           = 16;
$pw            = array_sum( $preview_sizes ) + $pad * ( count( $preview_sizes ) + 1 );
$ph            = 512 + $pad * 2;
$pv            = imagecreatetruecolor( $pw, $ph );
imagefill( $pv, 0, 0, imagecolorallocate( $pv, 245, 246, 248 ) );
$x = $pad;
foreach ( $preview_sizes as $s ) {
	$im = render_icon( $s, $primary, $white );
	imagecopy( $pv, $im, $x, $pad, 0, 0, $s, $s );
	imagedestroy( $im );
	$x += $s + $pad;
}
imagepng( $pv, "{$out_dir}/../../../../../scripts/logo-preview.png", 9 );
imagedestroy( $pv );

foreach ( $files as $f ) {
	printf( "  %-22s %7d B\n", $f, filesize( "{$out_dir}/{$f}" ) );
}
printf( "SVG/PNG 轮廓一致性校验通过（最大偏差 %.6f）\n", $dev );
echo "预览: scripts/logo-preview.png\n";
